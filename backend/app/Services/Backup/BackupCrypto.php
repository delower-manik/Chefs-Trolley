<?php

namespace App\Services\Backup;

/**
 * Streaming AES-256-GCM file encryption (so a big archive never has to fit in memory).
 *
 * Layout: "CTBK1\n" | salt(16) | nonce-prefix(8) | { len(4, big-endian) | ciphertext | tag(16) } ...
 * Each chunk's nonce is prefix + its index; its AAD is index + a "last chunk" flag, so a reordered, dropped,
 * truncated or tampered file fails to decrypt instead of silently restoring partial data.
 */
class BackupCrypto
{
    private const MAGIC = "CTBK1\n";

    private const CHUNK = 1048576; // 1 MiB

    public function __construct(private string $secret)
    {
        if ($secret === '') {
            throw new \RuntimeException('No backup encryption key is configured (BACKUP_ENCRYPTION_KEY or APP_KEY).');
        }
    }

    public static function fromConfig(): self
    {
        return new self((string) (config('backup.key') ?: config('app.key')));
    }

    private function key(string $salt): string
    {
        return hash_hkdf('sha256', $this->secret, 32, 'chefs-trolley-backup', $salt);
    }

    public function encryptFile(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if (! $src || ! $dst) {
            throw new \RuntimeException('Could not open files for encryption.');
        }
        $salt = random_bytes(16);
        $prefix = random_bytes(8);
        $key = $this->key($salt);
        fwrite($dst, self::MAGIC.$salt.$prefix);

        $index = 0;
        $chunk = (string) fread($src, self::CHUNK);
        while (true) {
            $next = feof($src) ? '' : (string) fread($src, self::CHUNK);
            $last = $next === '' ? 1 : 0;
            $tag = '';
            $ct = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix.pack('N', $index), $tag, pack('NC', $index, $last), 16);
            if ($ct === false) {
                throw new \RuntimeException('Encryption failed.');
            }
            fwrite($dst, pack('N', strlen($ct)).$ct.$tag);
            if ($last) {
                break;
            }
            $chunk = $next;
            $index++;
        }
        fclose($src);
        fclose($dst);
    }

    /** @throws \RuntimeException if the key is wrong or the file was altered or cut short */
    public function decryptFile(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if (! $src || ! $dst) {
            throw new \RuntimeException('Could not open files for decryption.');
        }
        $head = (string) fread($src, strlen(self::MAGIC) + 24);
        if (strlen($head) !== strlen(self::MAGIC) + 24 || ! str_starts_with($head, self::MAGIC)) {
            throw new \RuntimeException('This is not a Chefs Trolley backup file.');
        }
        $salt = substr($head, 6, 16);
        $prefix = substr($head, 22, 8);
        $key = $this->key($salt);

        $index = 0;
        $sawLast = false;
        while (! feof($src)) {
            $lenRaw = (string) fread($src, 4);
            if ($lenRaw === '') {
                break;
            }
            if (strlen($lenRaw) !== 4) {
                throw new \RuntimeException('The backup file is cut short.');
            }
            $len = unpack('N', $lenRaw)[1];
            if ($len > self::CHUNK + 64) {
                throw new \RuntimeException('The backup file is damaged.');
            }
            $ct = $len > 0 ? (string) fread($src, $len) : '';
            $tag = (string) fread($src, 16);
            if (strlen($ct) !== $len || strlen($tag) !== 16) {
                throw new \RuntimeException('The backup file is cut short.');
            }
            // Peek: is anything after this chunk?
            $more = ! feof($src) && ($peek = fread($src, 1)) !== '' && $peek !== false;
            if ($more) {
                fseek($src, -1, SEEK_CUR);
            }
            $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix.pack('N', $index), $tag, pack('NC', $index, $more ? 0 : 1));
            if ($plain === false) {
                throw new \RuntimeException('Could not decrypt: wrong key, or the file was altered or truncated.');
            }
            fwrite($dst, $plain);
            $sawLast = ! $more;
            $index++;
        }
        fclose($src);
        fclose($dst);
        if (! $sawLast) {
            throw new \RuntimeException('The backup file is cut short.');
        }
    }
}
