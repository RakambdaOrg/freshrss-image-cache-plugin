<?php

use ImageCache\Settings;

class ImageCacheExtension extends Minz_Extension
{
    public array $CACHE = [];
    public Settings $settings;

    public function autoload(string $class_name): void
    {
        $namespaceName = 'ImageCache';
        if (str_starts_with($class_name, $namespaceName)) {
            $class_name = substr($class_name, strlen($namespaceName) + 1);
            include $this->getPath() . DIRECTORY_SEPARATOR . str_replace('\\', '/', $class_name) . '.php';
        }
    }

    public function init(): void
    {
        spl_autoload_register([$this, 'autoload']);

        $this->settings = new Settings($this->getUserConfiguration());

        $this->registerTranslates();
        $this->registerCss();
        $this->registerScript();

        $this->registerHook("entry_before_display", [$this, "content_modification_hook"]);
        $this->registerHook("entry_before_insert", [$this, "image_upload_hook"]);
    }

    private function registerCss(): void
    {
        $css_file_name = "style.css";

        $this->saveFile($css_file_name, <<<EOT
img.cache-image, video.cache-image {
    min-width: 100px;
    width: auto !important;
    
    min-height: 100px;
    height: auto !important;
    max-height: 50vh !important;
    
    object-fit: contain;
    background-color: red;
}
EOT
        );

        Minz_View::appendStyle($this->getFileUrl($css_file_name, 'css', false));
    }

    private function registerScript(): void
    {
        $script_file_name = "script.js";

        $defaultVolume = $this->getDefaultVolume();
        $this->saveFile($script_file_name, <<<EOT
document.getElementById("stream").addEventListener("play", function(e) {
    if(e.target && e.target.nodeName == "video") {
        e.target.volume = $defaultVolume;
        console.log("Loaded data ", e);
    }
});
EOT
        );

        Minz_View::appendStyle($this->getFileUrl($script_file_name, 'js', false));
    }

    public function handleConfigureAction(): void
    {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            $configuration = [
                    'image_cache_url' => Minz_Request::paramString('image_cache_url'),
                    'image_cache_access_token' => Minz_Request::paramString('image_cache_access_token'),
                    'image_cache_disabled_url' => Minz_Request::paramString('image_cache_disabled_url'),
                    'image_recache_url' => Minz_Request::paramString('image_recache_url'),
                    'video_default_volume' => Minz_Request::paramString('video_default_volume'),
                    'upload_retry_count' => Minz_Request::paramInt('upload_retry_count'),
                    'max_cache_elements' => Minz_Request::paramInt('max_cache_elements'),
                    'remove_wrong_tag' => Minz_Request::paramBoolean('remove_wrong_tag'),
            ];
            $this->setUserConfiguration($configuration);
        }
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     * @throws Minz_PermissionDeniedException
     */
    public function content_modification_hook(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        $entry->_content(self::swapUrls($entry->content(), $entry));
        return $entry;
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     * @throws Minz_PermissionDeniedException
     */
    public function image_upload_hook(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        self::uploadUrls($entry->content(), $entry);
        return $entry;
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     * @throws Minz_PermissionDeniedException
     */
    private function swapUrls(string $content, FreshRSS_Entry $entry): string
    {
        if (empty($content)) {
            return $content;
        }

        $doc = self::loadContentAsDOM($content);
        self::handleImages($doc, $entry,
                "display",
                [$this, "getCachedUrl"],
                [$this, "getCachedSetUrl"]
        );

        return $doc->saveHTML();
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     * @throws Minz_PermissionDeniedException
     */
    private function uploadUrls(string $content, FreshRSS_Entry $entry): void
    {
        if (empty($content)) {
            return;
        }

        $doc = self::loadContentAsDOM($content);
        self::handleImages($doc, $entry,
                "insert",
                [$this, "uploadUrl"],
                [$this, "uploadSetUrls"]
        );
    }

    /** @noinspection PhpReturnValueOfMethodIsNeverUsedInspection */
    private function appendAfter(DOMNode $node, DOMNode $add): DOMNode|false
    {
        try {
            return $node->parentNode->insertBefore($add, $node->nextSibling);
        } catch (Exception) {
            return $node->parentNode->appendChild($add);
        }
    }

    /**
     * @throws FreshRSS_Context_Exception
     * @throws Minz_ConfigurationParamException
     * @throws Minz_PermissionDeniedException
     */
    private function handleImages(DOMDocument $doc, FreshRSS_Entry $entry, string $callSource, callable $singleElementCallback, callable $elementSetCallback): void
    {
        Minz_Log::debug("ImageCache[$callSource]: Scanning new document");

        $images = $doc->getElementsByTagName("img");
        $videos = $doc->getElementsByTagName("video");
        $links = $doc->getElementsByTagName("a");

        foreach ($images as $image) {
            if ($image->hasAttribute("src")) {
                $src = $image->getAttribute("src");
                if ($this->isDisabled($src)) {
                    Minz_Log::debug("ImageCache[$callSource]: Found disabled image $src");
                    continue;
                }

                Minz_Log::debug("ImageCache[$callSource]: Found image $src");
                $result = $singleElementCallback($src, $entry);
                if ($result) {
                    $image->setAttribute("previous-src", $src);
                    $image->setAttribute("src", $result);
                    $this->addDefaultImageAttributes($image);
                    Minz_Log::debug("ImageCache[$callSource]: Replaced image with $result");

                    if ($this->isVideoLink($src)) {
                        $this->appendVideo($doc, $image, $src, $result);
                        if ($this->settings->isRemoveWrongTag()) {
                            $image->parentNode->removeChild($image);
                        }
                    }
                } else {
                    Minz_Log::debug("ImageCache[$callSource]: Failed replacing image");
                }
            }
            if ($image->hasAttribute("srcset")) {
                $elementSetCall = function ($src) use ($elementSetCallback, $entry) {
                    return $elementSetCallback($src, $entry);
                };
                $srcSet = $image->getAttribute("srcset");
                Minz_Log::debug("ImageCache[$callSource]: Found image set $srcSet");
                /** @noinspection RegExpUnnecessaryNonCapturingGroup */
                $result = preg_replace_callback("/(?:([^\s,]+)(\s*(?:\s+\d+[wx])(?:,\s*)?))/", $elementSetCall, $srcSet);
                $result = array_filter($result);
                if ($result) {
                    $image->setAttribute("previous-srcset", $srcSet);
                    $image->setAttribute("srcset", $result);
                    $this->addDefaultImageAttributes($image);
                    /** @noinspection PhpArrayToStringConversionInspection */
                    Minz_Log::debug("ImageCache[$callSource]: Replaced with $result");
                } else {
                    Minz_Log::debug("ImageCache[$callSource]: Failed replacing image set");
                }
            }
        }

        foreach ($videos as $video) {
            Minz_Log::debug("ImageCache[$callSource]: Found video");

            if ($video->hasAttribute("src")) {
                $src = $video->getAttribute("src");
                if ($this->isDisabled($src)) {
                    Minz_Log::debug("ImageCache[$callSource]: Found disabled video $src");
                    continue;
                }

                Minz_Log::debug("ImageCache[$callSource]: Found video src $src");
                $result = $singleElementCallback($src, $entry);
                if ($result) {
                    $video->setAttribute("previous-src", $src);
                    $video->setAttribute("src", $result);
                    $this->addDefaultVideoAttributes($video);
                    Minz_Log::debug("ImageCache[$callSource]: Replaced with $result");
                } else {
                    Minz_Log::debug("ImageCache[$callSource]: Failed replacing video src");
                }
            }

            if ($video->hasAttribute("poster")) {
                $poster = $video->getAttribute("poster");
                if ($this->isDisabled($poster)) {
                    Minz_Log::debug("ImageCache[$callSource]: Found disabled video poster $poster");
                    continue;
                }

                Minz_Log::debug("ImageCache[$callSource]: Found video poster $poster");
                $result = $singleElementCallback($poster, $entry);
                if ($result) {
                    $video->setAttribute("previous-poster", $poster);
                    $video->setAttribute("poster", $result);
                    Minz_Log::debug("ImageCache[$callSource]: Replaced with $result");
                } else {
                    Minz_Log::debug("ImageCache[$callSource]: Failed replacing video poster");
                }
            }

            foreach ($video->childNodes as $source) {
                if ($source->nodeName == 'source') {
                    if (!$source->hasAttribute("src")) {
                        continue;
                    }

                    $src = $source->getAttribute("src");
                    if ($this->isDisabled($src)) {
                        Minz_Log::debug("ImageCache[$callSource]: Found disabled video $src");
                        continue;
                    }

                    Minz_Log::debug("ImageCache[$callSource]: Found video source $src");
                    $result = $singleElementCallback($src, $entry);
                    if ($result) {
                        $source->setAttribute("previous-src", $src);
                        $source->setAttribute("src", $result);
                        $this->overrideVideoSourceAttributes($source);
                        $this->addDefaultVideoAttributes($video);
                        $this->addClass($video, "cache-image");
                        Minz_Log::debug("ImageCache[$callSource]: Replaced with $result");
                    } else {
                        Minz_Log::debug("ImageCache[$callSource]: Failed replacing video source");
                    }
                }
            }
        }

        foreach ($links as $link) {
            if (!$link->hasAttribute("href")) {
                continue;
            }
            $href = $link->getAttribute("href");

            if (!$this->isImageLink($href) && !$this->isVideoLink($href)) {
                Minz_Log::debug("ImageCache[$callSource]: Found skipped link $href");
                continue;
            }
            if ($this->isDisabled($href)) {
                Minz_Log::debug("ImageCache[$callSource]: Found disabled link $href");
                continue;
            }

            Minz_Log::debug("ImageCache[$callSource]: Found link $href");
            $result = $singleElementCallback($href, $entry);
            if (!$result) {
                Minz_Log::debug("ImageCache[$callSource]: Failed replacing link");
                continue;
            }

            if ($this->isVideoLink($href)) {
                $this->appendVideo($doc, $link, $href, $result);
            } else {
                $this->appendImage($doc, $link, $href, $result);
            }
        }

        Minz_Log::debug("ImageCache[$callSource]: Done scanning document");
    }

    private function addClass(DOMElement $node, string $clazz): void
    {
        $previous = $node->getAttribute('class');
        if (!str_contains($previous, $clazz)) {
            $node->setAttribute('class', "$previous $clazz");
        }
    }

    /**
     * @throws Minz_PermissionDeniedException
     */
    private function appendImage(DOMDocument $doc, DOMElement $node, string $originalLink, string $newLink): void
    {
        try {
            $image = $doc->createElement('img');
            $image->setAttribute('src', $newLink);
            $image->setAttribute('previous-src', $originalLink);
            $this->addDefaultImageAttributes($image);

            $this->appendAfter($node, $this->wrapElement($doc, $image));
            Minz_Log::debug("[ImageCache]: Added image link with $originalLink => $newLink");
        } catch (Exception $e) {
            Minz_Log::error("[ImageCache]: Failed to create new DOM element $e");
        }
    }

    /**
     * @throws Minz_PermissionDeniedException
     */
    private function appendVideo(DOMDocument $doc, DOMElement $node, string $originalLink, string $newLink): void
    {
        try {
            $source = $doc->createElement('source');
            $source->setAttribute('src', $newLink);
            $source->setAttribute('previous-src', $originalLink);

            $video = $doc->createElement('video');
            $this->addDefaultVideoAttributes($video);
            $this->overrideVideoSourceAttributes($source);
            $video->appendChild($source);

            $this->appendAfter($node, $this->wrapElement($doc, $video));
            Minz_Log::debug("[ImageCache]: Added video link with $originalLink => $newLink");
        } catch (Exception $e) {
            Minz_Log::error("[ImageCache]: Failed to create new DOM element $e");
        }
    }

    /**
     * @throws DOMException
     */
    private function wrapElement(DOMDocument $doc, DOMElement $node): DOMElement
    {
        $div = $doc->createElement('div');
        $div->appendChild($node);
        return $div;
    }

    /**
     * @throws Minz_PermissionDeniedException
     */
    private function getCachedSetUrl(array $matches, FreshRSS_Entry $entry): string
    {
        return str_replace($matches[1], self::getCachedUrl($matches[1], $entry), $matches[0]);
    }

    /**
     * @throws Minz_PermissionDeniedException
     */
    private function getCachedUrl(string $url, FreshRSS_Entry $entry): string
    {
        $image_cache_url = $this->settings->getImageCacheUrl();
        if (str_starts_with($url, $image_cache_url)) {
            Minz_Log::debug("ImageCache: URL $url already starts with $image_cache_url");
            return $url;
        }
        if ($this->isRecache($url) && !$this->isUrlCached($url, $entry)) {
            Minz_Log::debug("ImageCache: Re-caching $url");
            $this->uploadUrl($url, $entry);
        }

        $params = array(
                'url' => $this->safe_encode_base64($url),
                'origin' => $this->safe_encode_base64($entry->link()),
                'authors' => $this->safe_encode_base64(json_encode($entry->authors())),
                'code' => $this->settings->getImageCacheAccessToken()
        );
        return $image_cache_url . '?' . http_build_query($params, encoding_type: PHP_QUERY_RFC3986);
    }

    private function isUrlCached(string $url, FreshRSS_Entry $entry): bool
    {
        if ($this->isCachedOnRemote($url)) {
            return true;
        }
        $params = array(
                'url' => $this->safe_encode_base64($url),
                'origin' => $this->safe_encode_base64($entry->link()),
                'authors' => $this->safe_encode_base64(json_encode($entry->authors())),
                'code' => $this->settings->getImageCacheAccessToken()
        );
        $cache_url = $this->settings->getImageCacheUrl() . '?' . http_build_query($params, encoding_type: PHP_QUERY_RFC3986);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $cache_url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code == 404) {
            return false;
        }
        if ($this->isRecache($url)) {
            $this->setCachedOnRemote($url);
        }
        return true;
    }

    /**
     * @throws Minz_PermissionDeniedException
     */
    private function uploadSetUrls(array $matches, FreshRSS_Entry $entry): bool
    {
        return self::uploadUrl($matches[1], $entry);
    }

    /**
     * @throws Minz_PermissionDeniedException
     */
    private function uploadUrl(string $to_cache_cache_url, FreshRSS_Entry $entry): bool
    {
        if ($this->isCachedOnRemote($to_cache_cache_url)) {
            Minz_Log::debug("ImageCache: URL $to_cache_cache_url is already cached on remote");
            return true;
        }
        $max_tries = 1 + $this->settings->getUploadRetryCount();
        for ($i = 1; $i <= $max_tries; $i++) {
            $cached = self::postUrl($this->settings->getImageCacheUrl(), [
                    "url" => $to_cache_cache_url,
                    "origin" => $entry->link(),
                    "authors" => $entry->authors(),
            ]);
            Minz_Log::debug("ImageCache: Try $i, $to_cache_cache_url cache result : $cached");
            if ($cached) {
                if ($this->isRecache($to_cache_cache_url)) {
                    $this->setCachedOnRemote($to_cache_cache_url);
                }
                return true;
            }
            sleep($this->settings->getUploadRetryDelay());
        }
        return false;
    }

    /**
     * @throws Minz_PermissionDeniedException
     */
    private function postUrl(string $url, array $data): bool
    {
        $data = json_encode($data);
        $dataLength = strlen($data);
        $curl = curl_init();
        $headers = [];

        $token = $this->settings->getImageCacheAccessToken();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json;charset='utf-8'",
                "Content-Length: $dataLength",
                "Accept: application/json",
                "Authorization: Bearer $token"
        ]);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION,
                function ($ch, $header) use (&$headers) {
                    $len = strlen($header);
                    $header = explode(':', $header, 2);
                    // ignore invalid headers
                    if (count($header) < 2) {
                        return $len;
                    }

                    $headers[strtolower(trim($header[0]))][] = trim($header[1]);
                    return $len;
                }
        );
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($curl);
        curl_close($curl);

        Minz_Log::debug("ImageCache: Upload response : $response");
        if (!$response) {
            return false;
        }

        $jsonPayload = json_decode($response, associative: true);
        if (!isset($jsonPayload["cached"])) {
            return false;
        }

        return !!$jsonPayload["cached"];
    }

    private function loadContentAsDOM(string $content): DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true); // prevent tag soup errors from showing
        $newContent = mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        $doc->loadHTML($newContent);
        return $doc;
    }

    private function isDisabled(string $src): bool
    {
        $disabledStr = $this->settings->getImageDisabledUrl();
        if (!$disabledStr) {
            return false;
        }

        $disabledEntries = explode(',', $disabledStr);
        foreach ($disabledEntries as $disabledEntry) {
            if (str_contains($src, $disabledEntry)) {
                return true;
            }
        }
        return false;
    }

    private function isRecache(string $src): bool
    {
        $recacheStr = $this->settings->getImageRecacheUrl();
        if (!$recacheStr) {
            return false;
        }

        $recacheEntries = explode(',', $recacheStr);
        foreach ($recacheEntries as $recacheEntry) {
            if (str_contains($src, $recacheEntry)) {
                return true;
            }
        }
        return false;
    }

    private function isVideoLink(string $src): bool
    {
        $parsed_url = parse_url($src);

        if (isset($parsed_url['host'])) {
            $host = $parsed_url['host'];
            if (str_contains($host, 'redgifs.com')
                    || str_contains($host, 'vidble.com')
            ) {
                return true;
            }
        }

        if (isset($parsed_url['path'])) {
            $path = $parsed_url['path'];
            if (str_ends_with($path, ".mp4")
//                || str_ends_with($path, ".gifv")
            ) {
                return true;
            }
        }

        return false;
    }

    private function isImageLink(string $src): bool
    {
        $parsed_url = parse_url($src);
        if (isset($parsed_url['host'])) {
            $host = $parsed_url['host'];
            if (str_contains($host, 'imgur.com')
                    || str_contains($host, 'i.redd.it')
            ) {
                return true;
            }
        }
        return false;
    }

    private function isCachedOnRemote(string $url): bool
    {
        return array_key_exists($url, $this->CACHE);
    }

    private function setCachedOnRemote(string $url): void
    {
        $count = count($this->CACHE);
        if ($count >= $this->settings->getMaxCacheElements()) {
            array_shift($this->CACHE);
        }
        $this->CACHE[] = $url;
    }

    private function getDefaultVolume(): float
    {
        return max(0, min(1, $this->settings->getVideoDefaultVolume()));
    }

    private function addDefaultImageAttributes(DOMElement $image): void
    {
        $this->addClass($image, "cache-image");
        $this->addClass($image, "reddit-image");
    }

    private function addDefaultVideoAttributes(DOMElement $video): void
    {
        $this->addClass($video, "cache-image");
        $this->addClass($video, "reddit-image");

        if (!$video->hasAttribute("controls")) {
            $video->setAttribute("controls", "true");
        }
        if (!$video->hasAttribute("autoplay")) {
            $video->setAttribute('autoplay', 'true');
        }
        if (!$video->hasAttribute("loop")) {
            $video->setAttribute("loop", "true");
        }
        if (!$video->hasAttribute("muted")) {
            if ($this->getDefaultVolume() <= 0) {
                $video->setAttribute('muted', 'true');
            }
        }
    }

    private function overrideVideoSourceAttributes(DOMElement $source): void
    {
        if ($source->hasAttribute("type")) {
            if ($source->getAttribute("type") === "application/x-mpegURL") {
                $source->setAttribute("type", "video/mp2t");
            }
        }
    }

    private function safe_encode_base64(string $value): string
    {
        return strtr(base64_encode($value), '+/=', '-_.');
    }
}
