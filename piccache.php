<?php

use JetBrains\PhpStorm\NoReturn;

class Config
{
    private static ?array $config = null;

    public static function get_config(): array
    {
        if (!Config::$config) {
            $configPath = getenv("PICCACHE_CONFIG_PATH");
            if (!$configPath) {
                $configPath = "/cache/config.json";
            }
            $json_content = file_get_contents($configPath);
            Config::$config = json_decode($json_content, associative: true);
        }
        return Config::$config;
    }

    public static function get_redgifs_bearer(): ?string
    {
        $config = self::get_config();
        if (isset($config['redgifs_bearer'])) {
            return $config['redgifs_bearer'];
        }
        return null;
    }

    public static function get_user_agent(): ?string
    {
        $config = self::get_config();
        if (isset($config['user_agent'])) {
            return $config['user_agent'];
        }
        return null;
    }

    public static function get_imgur_client_id(): ?string
    {
        $config = self::get_config();
        if (isset($config['imgur_client_id'])) {
            return $config['imgur_client_id'];
        }
        return null;
    }

    public static function get_hash_subfolder_count(): int
    {
        $config = self::get_config();
        if (isset($config['hash_subfolder_count'])) {
            return intval($config['hash_subfolder_count']);
        }
        return 3;
    }

    public static function get_root_folder(): string
    {
        $config = self::get_config();
        if (isset($config['root_folder'])) {
            return $config['root_folder'];
        }
        return "/cache/piccache";
    }

    public static function get_debug_enabled(): bool
    {
        $config = self::get_config();
        if (isset($config['debug'])) {
            return boolval($config['debug']);
        }
        return false;
    }

    public static function get_debug_path(): ?string
    {
        $config = self::get_config();
        if (isset($config['debug_path'])) {
            return $config['debug_path'];
        }
        return "/app/www/data/users/_/piccache_error.log";
    }

    public static function get_permissions(): int
    {
        $config = self::get_config();
        if (isset($config['permissions_all'])) {
            return 0777;
        }
        return 0775;
    }
}

if (Config::get_debug_enabled()) {
    $logFile = Config::get_debug_path();
    if (!file_exists($logFile)) {
        touch($logFile);
    }

    if (filesize($logFile) >= 1048576) { // 10Mb
        $fp = fopen($logFile, "w");
        fclose($fp);
    }

    ini_set("log_errors", 1);
    ini_set("log_errors_max_len", 2048);
    ini_set("error_log", $logFile);
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

class CacheHit
{
    public bool $fetched;
    public ?int $length;
    public ?string $filename;
    public ?string $content_type;

    public function __construct(bool $fetched = false, ?string $filename = null, ?int $length = 0, ?string $content_type = null)
    {
        $this->filename = $filename;
        $this->length = $length;
        $this->fetched = $fetched;
        $this->content_type = $content_type;
    }
}

class FetchHit
{
    public bool $cached;
    public bool $fetched;
    public ?string $filename;
    public ?array $headers;
    public ?string $comment;

    public function __construct(bool $cached = false, bool $fetched = false, ?string $filename = null, ?array $headers = [], ?string $comment = null)
    {
        $this->filename = $filename;
        $this->fetched = $fetched;
        $this->cached = $cached;
        $this->headers = $headers;
        $this->comment = $comment;
    }
}

class FetchLinkData
{
    public bool $fetched;
    /**
     * @var FetchHeader[]
     */
    public array $headers;
    public ?int $status_code;

    public function __construct(bool $fetched, ?int $status_code, array $headers = [])
    {
        $this->fetched = $fetched;
        $this->status_code = $status_code;
        $this->headers = $headers;
    }
}

class FetchHeader
{
    public string $name;
    public string $value;

    public function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }
}

class Cache
{
    private array $extensions;

    public function __construct()
    {
        $this->extensions = [
                'jpg' => 'jpg',
                'jpeg' => 'jpg',
                'png' => 'png',
                'gif' => 'gif',
                'svg' => 'svg',
                'svg+xml' => 'svg',
                'webp' => 'webp',
                'avif' => 'avif',
                'tiff' => 'tiff',

                'mp4' => 'mp4',
                'webm' => 'webm',

                'aac' => 'aac',
                'mp3' => 'mp3',
                'mpeg' => 'mp3',
        ];
    }

    private function join_paths(...$paths): string
    {
        return preg_replace('~[/\\\\]+~', DIRECTORY_SEPARATOR, implode(DIRECTORY_SEPARATOR, $paths));
    }

    private function get_filename($url): string
    {
        $hash_folder = $this->get_folder($url);
        if (!file_exists($hash_folder)) {
            umask(0);
            mkdir($hash_folder, recursive: true);
            chmod($hash_folder, Config::get_permissions());
        }

        $store_filename = explode('?', pathinfo($url, PATHINFO_BASENAME))[0];
        $store_extension = $this->get_filename_extension($url, $store_filename);

        if (!str_ends_with(".$store_filename", $store_extension)) {
            $store_filename = "$store_filename.$store_extension";
        }

        $url_hash = $this->get_url_hash($url);
        return $this->join_paths($hash_folder, "$url_hash-$store_filename");
    }

    private function get_folder(string $url): string
    {
        $url_hash = $this->get_url_hash($url);
        $parsed_url = parse_url($url);
        $host_parts = explode('.', $parsed_url['host']);
        $domain = implode('.', array_slice($host_parts, count($host_parts) - 2));
        $sub_hashes = [];
        $hash_subfolder_count = Config::get_hash_subfolder_count();
        for ($i = 0; $i < $hash_subfolder_count; $i++) {
            if ($i >= strlen($url_hash)) {
                $sub_hashes[] = "_";
            } else {
                $sub_hashes[] = $url_hash[$i];
            }
        }

        return $this->join_paths(Config::get_root_folder(), $domain, ...$sub_hashes);
    }

    private function get_filename_extension(string $url, string $store_filename): string
    {
        $path_extension = pathinfo($store_filename, PATHINFO_EXTENSION);
        if (!$this->isRedgifs($url) && !$this->isVidble($url) && array_key_exists($path_extension, $this->extensions)) {
            return $this->map_extension($path_extension);
        }

        $content_type = $this->extract_content_type($url);
        $content_type_extension = $this->get_extension_from_content_type($content_type);
        if ($content_type_extension && array_key_exists($content_type_extension, $this->extensions)) {
            return $this->map_extension($content_type_extension);
        }

        if ($this->isRedgifs($url)) {
            return $this->map_extension('mp4');
        }
        if ($this->isVidble($url)) {
            return $this->map_extension('mp4');
        }

        return $path_extension;
    }

    private function get_extension_from_content_type(?string $content_type): ?string
    {
        if (!$content_type) {
            return null;
        }

        $parsed_content_type = $this->parse_content_header_value($content_type);
        if (!$parsed_content_type || !isset($parsed_content_type["value"])) {
            return null;
        }

        $content_type_value = $parsed_content_type["value"];
        if (str_starts_with($content_type_value, "image/")
                || str_starts_with($content_type_value, "video/")
                || str_starts_with($content_type_value, "audio/")
        ) {

            $parts = explode('/', $content_type_value, 2);
            return $parts[1];
        }

        return null;
    }

    private function map_extension(?string $extension): ?string
    {
        if (!$extension || !array_key_exists($extension, $this->extensions)) {
            return $extension;
        }
        return $this->extensions[$extension];
    }

    private function extract_content_type(string $url): ?string
    {
        $raw_content_type = null;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION,
                function ($curl, $header) use (&$raw_content_type) {
                    $len = strlen($header);

                    $header = explode(':', $header, 2);
                    if (count($header) < 2) { // ignore invalid headers
                        return $len;
                    }

                    if (strtolower(trim($header[0])) == "content-type") {
                        $raw_content_type = trim($header[1]);
                    }
                    return $len;
                }
        );
        curl_exec($ch);
        curl_close($ch);
        return $raw_content_type;
    }

    private function parse_content_header_value(string $value): array
    {
        $retVal = array();
        $value_pattern = '/^([^;]+)\s*(.*)\s*?$/';
        $param_pattern = '/([a-z]+)=(([^\"][^;]+)|(\"(\\\"|[^"])+\"))/';
        $vm = array();

        if (preg_match($value_pattern, $value, $vm)) {
            $retVal['value'] = $vm[1];
            if (count($vm) > 1) {
                $pm = array();
                if (preg_match_all($param_pattern, $vm[2], $pm)) {
                    $pcount = count($pm[0]);
                    for ($i = 0; $i < $pcount; $i++) {
                        $value = $pm[2][$i];
                        if (str_starts_with($value, '"')) {
                            $value = stripcslashes(substr($value, 1, mb_strlen($value) - 2));
                        }
                        $retVal['params'][$pm[1][$i]] = $value;
                    }
                }
            }
        }

        return $retVal;
    }

    private function fetch_link_content(string $url, string $file_name): FetchLinkData
    {
        $user_agent = Config::get_user_agent();

        if ($this->isRedgifs($url)) {
            $url = $this->get_redgifs_url_from_m3u8($url);
            if (!$url) {
                return new FetchLinkData(false, null);
            }
        }
        if ($this->isVidble($url)) {
            $url = $this->get_vidble_url($url);
            if (!$url) {
                return new FetchLinkData(false, null);
            }
        }
        if ($this->isImgur($url)) {
            $url = $this->get_imgur_url($url);
            if (!$url) {
                return new FetchLinkData(false, null);
            }
        }

        $headers = [];
        $headersDumper = function ($ch, $header) use (&$headers) {
            $len = strlen($header);

            $header_parts = explode(':', $header, 2);
            if (count($header_parts) < 2) { // ignore invalid headers
                return $len;
            }
            $headers[] = new FetchHeader(trim($header_parts[0]), trim($header_parts[1]));
            return $len;
        };

        set_time_limit(0);
        $fp = fopen($file_name, 'w+');

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_USERAGENT, $user_agent);
        curl_setopt($curl, CURLOPT_TIMEOUT, 600);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_FILE, $fp);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, $headersDumper);

        $result = curl_exec($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        fclose($fp);

        if ($result) {
            umask(0);
            chmod($file_name, Config::get_permissions());
        }

        return new FetchLinkData($result, $status_code ? intval($status_code) : null, $headers);
    }

    private function get_redgifs_url_from_api(string $url): ?string
    {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'];
        $gif_id = basename($path);

        $user_agent = Config::get_user_agent();
        $bearer = Config::get_redgifs_bearer();

        $context = stream_context_create(["http" => [
                'header' => "Authorization: Bearer $bearer\r\nUser-Agent: $user_agent\r\n"
        ]]);
        $api_response = file_get_contents("https://api.redgifs.com/v2/gifs/$gif_id?views=yes&users=yes&niches=yes", false, $context);
        if (!$api_response) {
            return null;
        }

        $json_response = json_decode($api_response, associative: true);
        if (!$json_response) {
            return null;
        }
        return $json_response['gif']['urls']['hd'];
    }

    private function get_redgifs_url_from_m3u8(string $url): ?string
    {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'];
        $gif_id = basename($path);

        $user_agent = Config::get_user_agent();
        $bearer = Config::get_redgifs_bearer();

        $context = stream_context_create(["http" => [
                'header' => "Authorization: Bearer $bearer\r\nUser-Agent: $user_agent\r\n"
        ]]);
        $api_response = file_get_contents("https://api.redgifs.com/v2/gifs/$gif_id/hd.m3u8", false, $context);
        if (!$api_response) {
            return null;
        }

        $pm = array();
        if (preg_match_all('/^(?!#).*/mi', $api_response, $pm)) {
            foreach ($pm as $v) {
                return $v[0];
            }
        }

        return null;
    }

    private function get_vidble_url(string $url): ?string
    {
        preg_match('/watch\\?v=([a-zA-Z0-9]+)/', $url, $matches);
        if ($matches && isset($matches[1])) {
            $video_id = $matches[1];
            return "https://www.vidble.com/$video_id.mp4";
        }
        return null;
    }

    private function get_imgur_url(string $url): ?string
    {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'];
        $post_id = implode(explode('.', basename($path), -1));

        if (str_ends_with($path, '.gifv')) {
            $direct_url = "https://i.imgur.com/$post_id.mp4";
            $content_type = $this->extract_content_type($direct_url);
            if ($content_type) {
                if (str_starts_with($content_type, 'image/') || str_starts_with($content_type, 'video/')) {
                    return $direct_url;
                }
            }
        }

        $user_agent = Config::get_user_agent();
        $bearer = Config::get_imgur_client_id();

        $context = stream_context_create(["http" => [
                'header' => "Authorization: Client-ID $bearer\r\nUser-Agent: $user_agent\r\n"
        ]]);

        $api_response = file_get_contents("https://api.imgur.com/3/image/$post_id", false, $context);
        if (!$api_response || $this->content_type_contains($http_response_header, 'text/html')) {
            return null;
        }

        $json_response = json_decode($api_response, associative: true);
        if (!$json_response || !$json_response["success"]) {
            return null;
        }
        return $json_response['data']['link'];
    }

    private function isRedgifs(string $url): bool
    {
        $parsed_url = parse_url($url);
        return str_contains($parsed_url['host'], 'redgifs.com');
    }

    private function isVidble(string $url): bool
    {
        $parsed_url = parse_url($url);
        return str_contains($parsed_url['host'], 'vidble.com');
    }

    private function isImgur(string $url): bool
    {
        $parsed_url = parse_url($url);
        return str_contains($parsed_url['host'], 'imgur.com');
    }

    public function store_in_cache(string $url): FetchHit
    {
        $cached = $this->is_cached($url);
        if ($cached) {
            return new FetchHit(true, false, filename: $cached, comment: 'File already exists in cache');
        }

        $file_name = $this->get_filename($url);
        $data = $this->fetch_link_content($url, $file_name);
        if (!$data->fetched) {
            unlink($file_name);

            if ($data->status_code === null || $data->status_code === 404) {
                return new FetchHit(true, false, headers: $data->headers, comment: 'Got 404');
            }

            return new FetchHit(false, false, headers: $data->headers, comment: 'Could not get media content');
        }

        if (filesize($file_name) === 0) {
            unlink($file_name);
            return new FetchHit(false, true, $file_name, $data->headers, 'Content size is empty');
        }
        
        if ($this->content_type_contains($data->headers, 'text/html')) {
            unlink($file_name);
            return new FetchHit(false, true, headers: $data->headers, comment: 'Response has HTML content type');
        }

        if (file_exists($file_name)) {
            $file_object = new SplFileObject($file_name, 'r');
            $line = $file_object->current();
            $file_object = null; // Close object
            
            if ($line && preg_match("#^\s*<!doctype html>.*#i", $line)) {
                unlink($file_name);
                return new FetchHit(false, true, headers: $data->headers, comment: 'Response was HTML');
            }
        }

        return new FetchHit(true, true, $file_name, $data->headers, 'Added to cache');
    }

    public function get_cached_data(string $url): CacheHit
    {
        $this->store_in_cache($url);
        $cached = $this->is_cached($url);
        if (!$cached) {
            return new CacheHit();
        }

        $file_size = filesize($cached);
        $content_type = mime_content_type($cached);
        return new CacheHit(true, $cached, $file_size, $content_type);
    }

    private function is_cached(string $url): ?string
    {
        $folder = $this->get_folder($url);
        $hash = $this->get_url_hash($url);
        $matched = glob("$folder/$hash*", GLOB_NOSORT);
        if ($matched) {
            return $matched[0];
        }
        return null;
    }

    private function get_url_hash(string $url): string
    {
        return hash('sha256', $url);
    }

    private function content_type_contains(array $headers, string $type): bool
    {
        if (!$headers or !isset($headers['Content-Type'])) {
            return false;
        }
        if (str_contains($headers['Content-Type'], $type)) {
            return true;
        }
        return false;
    }
}

#[NoReturn]
function end_wrong_query(): void
{
    http_response_code(400);
    exit();
}

function reply_video(CacheHit $cache_hit): void
{
    $filesize = $cache_hit->length;
    $length = $filesize;
    $offset = 0;

    if (isset($_SERVER['HTTP_RANGE'])) {
        $partialContent = true;
        preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches);

        $offset = intval($matches[1]);
        $end = (isset($matches[2]) && $matches[2]) ? intval($matches[2]) : ($filesize - 1);
        $length = $end - $offset + 1;
    } else {
        $partialContent = false;
    }

    $file = fopen($cache_hit->filename, 'r');
    fseek($file, $offset);
    $data = fread($file, $length);
    fclose($file);

    if ($partialContent) {
        $offset_end = $offset + $length - 1;
        header("HTTP/1.1 206 Partial Content");
        header("Content-Range: bytes $offset-$offset_end/$filesize");
        header("Content-Length: $length");
    } else {
        header("Content-Length: $filesize");
    }

    $filename = pathinfo($cache_hit->filename, PATHINFO_BASENAME);

    header("X-Piccache-Status: HIT");
    header("X-Piccache-File: $cache_hit->filename");
    header("Content-Type: $cache_hit->content_type");
    header("Content-Disposition: inline; filename=\"$filename\"");
    header("Accept-Ranges: bytes");

    print($data);
}

try {
    $cache = new Cache();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_data = json_decode(file_get_contents('php://input'), true);
        if (!$post_data || !array_key_exists("url", $post_data)) {
            header("X-Piccache-Error: No URL provided");
            end_wrong_query();
        }

        $url = $post_data['url'];
        $fetchHit = $cache->store_in_cache($url);
        header('Content-Type: application/json; charset=utf-8');
        header("X-Piccache-Url: $url");
        echo json_encode($fetchHit) . PHP_EOL;

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!isset($_GET['url'])) {
            header("X-Piccache-Error: No URL provided");
            end_wrong_query();
        }
        $url = $_GET['url'];
        if (!$url) {
            header("X-Piccache-Error: No URL provided");
            end_wrong_query();
        }

        $cache_hit = $cache->get_cached_data($url);
        if (!$cache_hit->fetched) {
            header('X-Piccache-Status: MISS');
            header("X-Piccache-Url: $url");
            header("Location: $url");
            http_response_code(302);
            exit();
        } else {
            if (str_starts_with($cache_hit->content_type, 'video/')) {
                reply_video($cache_hit);
            } else {
                header("X-Piccache-Status: HIT");
                header("X-Piccache-Url: $url");
                header("X-Piccache-File: $cache_hit->filename");
                header("Content-Type: $cache_hit->content_type");
                header("Content-Length: $cache_hit->length");
                fpassthru(fopen($cache_hit->filename, 'rb'));
            }
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        $url = $_GET['url'];
        if (!$url) {
            header("X-Piccache-Error: No URL provided");
            end_wrong_query();
        }

        $cache_hit = $cache->get_cached_data($url);
        if (!$cache_hit->fetched) {
            header('X-Piccache-Status: MISS');
            header("X-Piccache-Url: $url");
            http_response_code(404);
            exit();
        } else {
            header('X-Piccache-Status: HIT');
            header("X-Piccache-Url: $url");
            header("X-Piccache-File: $cache_hit->filename");
            header("Content-Type: $cache_hit->content_type");
            header("Content-Length: $cache_hit->length");
            http_response_code(204);
        }

    } else {
        http_response_code(405);
    }
} catch (Exception $e) {
    header("X-Piccache-Error: Uncaught exception");
    header("Content-Type: text/plain");
    http_response_code(500);
    error_log($e);
}
exit();
