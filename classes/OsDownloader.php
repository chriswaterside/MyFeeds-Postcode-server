<?php
declare(strict_types=1);

namespace RaPostcode;

use RuntimeException;

/** Finds and downloads the current Code-Point Open CSV release from the OS Downloads API (no API key needed). */
final class OsDownloader
{
    public function __construct(private string $baseUrl, private Logger $log, private ?string $caBundle = null)
    {
        if ($caBundle !== null && $caBundle !== '' && !is_file($caBundle)) {
            throw new RuntimeException("ca_bundle file not found: $caBundle");
        }
    }

    /** Extra curl options: use the configured CA bundle (needed on Windows/WAMP, where PHP has none by default). */
    private function tlsOptions(): array
    {
        return $this->caBundle ? [CURLOPT_CAINFO => $this->caBundle] : [];
    }

    /**
     * Release metadata. The API answers a request without &redirect with JSON like
     * [{"md5":"…","size":123,"url":"…&redirect","format":"CSV","area":"GB","fileName":"codepo_gb.zip"}]
     *
     * @return array{md5:string,size:int,url:string,fileName:string}
     */
    public function fetchMetadata(): array
    {
        $json = $this->httpGet($this->baseUrl);
        $list = json_decode($json, true);
        if (!is_array($list) || $list === []) {
            throw new RuntimeException('OS metadata response was not the expected JSON list');
        }
        $pick = null;
        foreach ($list as $item) {
            if (is_array($item) && ($item['format'] ?? '') === 'CSV' && ($item['area'] ?? '') === 'GB') {
                $pick = $item;
                break;
            }
        }
        if ($pick === null || empty($pick['url'])) {
            throw new RuntimeException('OS metadata did not contain a GB CSV download');
        }
        return [
            'md5' => strtolower((string) ($pick['md5'] ?? '')),
            'size' => (int) ($pick['size'] ?? 0),
            'url' => (string) $pick['url'],
            'fileName' => (string) ($pick['fileName'] ?? 'codepo_gb.zip'),
        ];
    }

    /** Download to $dest, verifying size and md5 when OS supplies them. */
    public function download(array $meta, string $dest): void
    {
        $this->log->info("Downloading {$meta['url']}");
        $fh = fopen($dest, 'wb');
        if (!$fh) {
            throw new RuntimeException("Cannot write $dest");
        }
        $ch = curl_init($meta['url']);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'ramblers-postcode-feed/2',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ] + $this->tlsOptions());
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($ok === false) {
            @unlink($dest);
            throw new RuntimeException("Download failed: $err");
        }
        $size = filesize($dest);
        if ($meta['size'] > 0 && $size !== $meta['size']) {
            throw new RuntimeException("Downloaded size $size does not match expected {$meta['size']}");
        }
        if ($meta['md5'] !== '' && md5_file($dest) !== $meta['md5']) {
            throw new RuntimeException('Downloaded file failed the md5 check');
        }
        $this->log->info("Downloaded $size bytes, checksum OK");
    }

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'ramblers-postcode-feed/2',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ] + $this->tlsOptions());
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException("Request to $url failed: $err");
        }
        return $body;
    }
}
