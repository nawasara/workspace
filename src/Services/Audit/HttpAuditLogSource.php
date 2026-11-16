<?php

namespace Nawasara\Workspace\Services\Audit;

use Illuminate\Support\Facades\Http;

/**
 * admin_audit log served read-only over HTTP with Range support (see
 * AuditLogSource). Any static file server does Range (nginx does by
 * default), so the host side is one location block, no code.
 */
class HttpAuditLogSource implements AuditLogSource
{
    private const HEAD_BYTES = 64 * 1024;

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeout = 15,
    ) {}

    public function name(): string
    {
        return 'http';
    }

    public function isConfigured(): bool
    {
        return $this->url !== '';
    }

    public function probe(): array
    {
        $res = $this->get(0, self::HEAD_BYTES);

        if ($res->status() === 416) {
            return ['size' => $this->totalFrom($res) ?? 0, 'fingerprint' => null];
        }

        $this->assertOk($res);
        $body = $res->body();
        $size = $res->status() === 206 ? ($this->totalFrom($res) ?? strlen($body)) : strlen($body);

        return ['size' => $size, 'fingerprint' => FileAuditLogSource::fingerprint(substr($body, 0, self::HEAD_BYTES))];
    }

    public function read(int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $res = $this->get($offset, $length);
        if ($res->status() === 416) {
            return '';
        }

        $this->assertOk($res);

        // 200 = the server ignored Range and sent the whole file.
        return $res->status() === 206 ? $res->body() : substr($res->body(), $offset, $length);
    }

    private function get(int $offset, int $length): \Illuminate\Http\Client\Response
    {
        $req = Http::timeout($this->timeout)
            ->withHeaders(['Range' => 'bytes='.$offset.'-'.($offset + $length - 1), 'Accept-Encoding' => 'identity']);

        if ($this->token !== '') {
            $req = $req->withToken($this->token);
        }

        return $req->get($this->url);
    }

    private function totalFrom(\Illuminate\Http\Client\Response $res): ?int
    {
        return preg_match('#/(\d+)\s*$#', (string) $res->header('Content-Range'), $m) ? (int) $m[1] : null;
    }

    private function assertOk(\Illuminate\Http\Client\Response $res): void
    {
        if (! in_array($res->status(), [200, 206], true)) {
            // Never echo the body: it could be an error page with details.
            throw new \RuntimeException('Log admin_audit lewat HTTP gagal dibaca: HTTP '.$res->status());
        }
    }
}
