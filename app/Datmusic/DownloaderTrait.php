<?php
/**
 * Copyright (c) 2017  Alashov Berkeli
 * It is licensed under GNU GPL v. 2 or later. For full terms see the file LICENSE.
 */

namespace App\Datmusic;

use Aws\S3\S3Client;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use phpDocumentor\Reflection\Types\Resource;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Utils;

trait DownloaderTrait
{
    /**
     * @var S3Client
     */
    protected $s3Client;
    /**
     * @var Resource S3 atream context resource
     */
    protected $s3StreamContext;
    /**
     * @var bool is using s3 as storage
     */
    protected $isS3 = false;

    /**
     * DownloaderTrait constructor.
     */
    public function bootDownloader()
    {
        if (config('app.aws.enabled')) {
            $this->s3Client = new S3Client(config('app.aws.config'));
            $this->s3Client->registerStreamWrapper();
            $this->isS3 = true;
        }
    }

    /**
     * Get size of audio file in bytes
     * @param $key
     * @param $id
     * @return mixed
     */
    public function bytes($key, $id)
    {
        $cacheKey = "bytes_$id";

        // get from cache or store in cache and return value
        return Cache::rememberForever($cacheKey, function () use ($key, $id) {
            $item = $this->getAudio($key, $id);

            $httpClient = HttpClient::getInstance()->getClient();
            $response = $httpClient->head($item['mp3']);
            return $response->getHeader('Content-Length')[0];
        });
    }

    /**
     * Just like download but with stream enabled
     * @param string $key
     * @param string $id
     * @return mixed
     */
    public function stream($key, $id)
    {
        return $this->download($key, $id, true);
    }

    /**
     * Just like download but with bitrate converting enabled
     * @param string $key
     * @param string $id
     * @param int $bitrate
     * @return mixed
     */
    public function bitrateDownload($key, $id, $bitrate)
    {
        return $this->download($key, $id, false, $bitrate);
    }

    /**
     * Serves given audio item or aborts with 404 if not found
     * @param string $key
     * @param string $id
     * @param bool $stream
     * @param int $bitrate
     * @return mixed
     */
    public function download($key, $id, $stream = false, $bitrate = -1)
    {
        if (!in_array($bitrate, config('app.conversion.allowed'))) {
            $bitrate = -1;
        }

        // filename including extension
        $filePath = sprintf('%s.mp3', hash(config('app.hash.mp3'), $id));

        if (config('app.paths.subfolders') > 0) {
            $subfolder = '';
            for ($i = 0; $i < config('app.paths.subfolders'); $i++) {
                $subfolder .= $filePath{$i} . '/';
            }
            $filePath = $subfolder . $filePath;
        }

        // used for bitrate converting when using s3
        $localPath = sprintf('%s/%s', config('app.paths.mp3'), $filePath);

        // build full path from file path
        if ($this->isS3) {
            $s3PathWithFolder = sprintf(config('app.aws.paths.mp3'), $filePath);
            $path = sprintf('s3://%s/%s', config('app.aws.bucket'), $s3PathWithFolder);
        } else {
            $path = $localPath;
            $dirname = dirname($path);
            if (!is_dir($dirname)) {
              // Create subdirectory recursively
              mkdir($dirname, 0750, TRUE);
            }
        }

        // cache check only for s3.
        // check bucket for file and redirect if exists
        if ($this->isS3 && @file_exists($this->formatPathWithBitrate($path, $bitrate))) {
            return redirect($this->buildS3Url($this->formatPathWithBitrate($filePath, $bitrate)));
        }

        $item = $this->getAudio($key, $id);
        $name = $this->getFormattedName($item);

        if ($this->isS3) {
            $this->s3StreamContext = $this->buildS3StreamContextOptions($name);
        }

        if (!config('app.conversion.disable') && $stream) {
            if (@file_exists($path)) {
                // Immediate response
                if ($this->isS3) {
                    return redirect($this->buildS3Url($filePath));
                } else {
                    return redirect("mp3/$filePath");
                }
            }

            $this->streamFile($item['mp3'], $path);
        } else {
            if (@file_exists($path) || $this->downloadFile($item['mp3'], $path)) {
                if (!config('app.conversion.disable')) {
                    $convertResult = $this->bitrateConvert($bitrate, $path, $localPath, $filePath);

                    if ($convertResult != false) {
                        list($filePath, $path) = $convertResult;
                    }
                }
                if ($this->isS3) {
                    return redirect($this->buildS3Url($filePath));
                } else {
                    if ($stream) {
                        return redirect("mp3/$filePath");
                    } else {
                        return $this->downloadResponse($path, $name);
                    }
                }
            } else {
                abort(404);
            }

        }
        return 0;
    }

    /**
     * @param $bitrate
     * @param $path
     * @param $localPath
     * @param $filePath
     * @return array|bool
     */
    private function bitrateConvert($bitrate, $path, $localPath, $filePath)
    {
        if ($bitrate > 0) {
            // Download to local if s3 mode and upload converted one to s3
            // Change path only if already converted or conversion function returns true

            $pathConverted = $this->formatPathWithBitrate($localPath, $bitrate);
            $filePathConverted = $this->formatPathWithBitrate($filePath, $bitrate);

            // s3 mode
            if ($this->isS3) {
                // download file from s3 to local
                // continue only if download succeeds
                $convertable = $this->downloadFile($this->buildS3Url($filePath), $localPath);
            } else {
                $convertable = true;
            }

            if ($convertable) {
                if (file_exists($pathConverted)
                    || $this->convertMp3Bitrate($bitrate, $localPath, $pathConverted)
                ) {
                    // upload converted file
                    if ($this->isS3) {
                        $converted = fopen($pathConverted, 'r');
                        $s3ConvertedPath = $this->formatPathWithBitrate($path, $bitrate);
                        $s3Stream = fopen($s3ConvertedPath, 'w', false, $this->s3StreamContext);

                        // if upload succeeds
                        if (stream_copy_to_stream($converted, $s3Stream) != false) {
                            $convertedPaths = [$filePathConverted, $path];
                        }
                    } else {
                        $convertedPaths = [$filePathConverted, $pathConverted];
                    }
                }
            }
        }
        return isset($convertedPaths) ? $convertedPaths : false;
    }

    /**
     * Formats name, appends mp3, ascii-fy and remove bad characters
     * @param array $item
     * @return string formatted name
     */
    function getFormattedName($item)
    {
        $name = sprintf('%s - %s', $item['artist'], $item['title']);
        $name = Str::ascii($name);
        $name = Utils::sanitize($name, false, false);
        $name = sprintf('%s.mp3', $name);

        return $name;
    }

    /**
     * Download given file url to given path
     * @param string $url
     * @param string $path
     * @param resource $context stream context options when opening $path
     * @return bool true if succeeds
     */
    function downloadFile($url, $path)
    {
        if ($this->s3StreamContext == null) {
            $handle = fopen($path, 'w');
        } else {
            $handle = fopen($path, 'w', false, $this->s3StreamContext);
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_FILE, $handle);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_FAILONERROR, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, config('app.downloading.timeout.connection'));
        curl_setopt($curl, CURLOPT_TIMEOUT, config('app.downloading.timeout.execution'));
        curl_exec($curl);

        // if curl had errors
        if (curl_errno($curl) > 0) {
            // remove the file just in case
            @unlink($path);
            return false;
        }

        //close files
        curl_close($curl);
        fclose($handle);

        return true;
    }

    /**
     * Stream given file into User's browser by url and put it into
     * cache to given path
     * @param string $url
     * @param string $path
     * @param resource $context stream context options when opening $path
     * @return bool true if succeeds
     */
    function streamFile($url, $path) {
        $filesize = $this->getFileSize($url);

        header("Content-Length: $filesize");
        header('Content-Type: audio/mpeg');

        if ($this->s3StreamContext == null) {
            $handle = fopen($path, 'w');
        } else {
            $handle = fopen($path, 'w', false, $this->s3StreamContext);
        }

        $request = new \cURL\Request($url);

        $request->getOptions()
                ->set(CURLOPT_HEADER, 0)
                ->set(CURLOPT_FAILONERROR, 1)
                ->set(CURLOPT_CONNECTTIMEOUT, config('app.downloading.timeout.connection'))
                ->set(CURLOPT_TIMEOUT, config('app.downloading.timeout.execution'));

        ob_start();
        // Store a chunks of output buffer in a separate variable.
        $content = '';

        while ($request->socketPerform()) {
            // do anything else when the requests are processed
            $request->socketSelect();
            // line below pauses execution until there's new data on socket
            $content = ob_get_contents();
            fwrite($handle, $content, strlen($content));

            ob_flush();
            ob_clean();
        }

        // We should ensure that output buffer is completely written into a file.
        $content = ob_get_contents();
        fwrite($handle, $content, strlen($content));
        ob_flush();
        ob_clean();

        // if curl had errors
        if (curl_errno($request->getHandler()) > 0) {
            // remove the file just in case
            @unlink($path);
            return false;
        }

        fclose($handle);
        curl_close($curl);

        return $result;
    }

    /**
     * Get the size of remote file
     * @param string $url
     * @return int file size if succeeds
     */
    function getFileSize($url)
    {

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_NOBODY, 1);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FAILONERROR, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, config('app.downloading.timeout.connection'));
        curl_setopt($curl, CURLOPT_TIMEOUT, config('app.downloading.timeout.execution'));
        $data = curl_exec($curl);

        // if curl had errors
        if (curl_errno($curl) > 0) {
            // remove the file just in case
            @unlink($path);
            return false;
        }

        $content_length = 0;

        if (preg_match( "/Content-Length: (\d+)/", $data, $matches)) {
            $content_length = (int)$matches[1];
        }

        return $content_length;
    }

    /**
     * Checks given files mime type and abort with 404 if file is not an mp3 file
     * @param string $path full path of mp3
     */
    function checkIsBadMp3($path)
    {
        // valid mimes
        $validMimes = ['audio/mpeg', 'audio/mp3'];

        $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
        // checks mime-type with unix file command
        $nativeCheck = function () use ($path) {
            return exec("file -b --mime-type $path");
        };

        $checks = [$mime, $nativeCheck()];

        // if the file is corrupted (mime is wrong), delete it from storage and return 404
        // if arrays don't have any common values, mp3 is broken.
        if (!count(array_intersect($checks, $validMimes))) {
            @unlink($path);
            abort(404);
        }
    }

    /**
     * Force download given file with given name
     * @param $path string path of the file
     * @param $name string name of the downloading file
     * @return BinaryFileResponse
     */
    function downloadResponse($path, $name)
    {
        if (!file_exists($path)) {
            abort(404);
        }

        $this->checkIsBadMp3($path);

        $headers = [
            'Cache-Control' => 'private',
            'Cache-Description' => 'File Transfer',
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => filesize($path),
        ];

        return response()->download(
            $path,
            $name,
            $headers
        );
    }

    /**
     * Executes ffmpeg command synchronously for converting given file to given bitrate
     * @param $bitrate integer, one of $config["allowed_bitrates"]
     * @param $input string input mp3 file full path
     * @param $output string output mp3 file full path
     * @return bool is success
     */
    function convertMp3Bitrate($bitrate, $input, $output)
    {
        $bitrateString = config('app.conversion.allowed_ffmpeg')[array_search($bitrate,
            config('app.conversion.allowed'))];
        $ffmpegPath = config('app.conversion.ffmpeg_path');

        exec("$ffmpegPath -i $input -codec:a libmp3lame $bitrateString $output", $exOutput,
            $result);

        return $result == 0;
    }

    /**
     * @param string $path path to mp3
     * @param int $bitrate bitrate
     * @return string path_bitrate.mp3 formatted path
     */
    private function formatPathWithBitrate($path, $bitrate)
    {
        if ($bitrate > 0) {
            return str_replace('.mp3', "_$bitrate.mp3", $path);
        } else {
            return $path;
        }
    }

    // s3 utils

    /**
     * Builds url with region and bucket name from config
     * @param string $fileName path to file
     * @return string full url
     */
    private function buildS3Url($fileName)
    {
        if (env('CDN_ROOT_URL', null) !== null) {
            return sprintf('%s%s', env('CDN_ROOT_URL'), $fileName);
        }

        $region = config('app.aws.config.region');
        $bucket = config('app.aws.bucket');
        $path = sprintf(config('app.aws.paths.mp3'), $fileName);

        return "https://s3-$region.amazonaws.com/$bucket/$path";
    }

    /**
     * Builds S3 schema stream context options
     * All options available at http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-s3-2006-03-01.html#putobject
     * @param string $name Force download file name
     * @return resource
     */
    private function buildS3StreamContextOptions($name)
    {
        return stream_context_create([
            's3' => [
                'ACL' => 'public-read',
                'ContentType' => 'audio/mpeg',
                'ContentDisposition' => "attachment; filename=\"$name\"",
                'StorageClass' => 'STANDARD_IA'
            ]
        ]);
    }
}