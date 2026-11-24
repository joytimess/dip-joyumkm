<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

/**
 * Payload callback Hermes yang sudah didekode ketat dari raw body.
 *
 * Dekode ganda: array asosiatif untuk validasi dan objek untuk penyimpanan, supaya `{}` tidak berubah menjadi `[]`.
 */
final class IntelligenceCallbackPayload
{
    private const JSON_DEPTH = 32;

    private const DECODE_FLAGS = JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING;

    private const ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /** Metadata yang ikut dalam hash semantik laporan. */
    private const HASHED_METADATA = [
        'event_id', 'evaluation_id', 'umkm_id', 'source_event_version', 'schema_version',
        'prompt_version', 'knowledge_version', 'methodology_status', 'provider', 'model',
    ];

    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly array $data,
        private readonly stdClass $document,
    ) {}

    /**
     * @throws ValidationException bila body bukan satu objek JSON yang valid dan tidak ambigu.
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, self::JSON_DEPTH, self::DECODE_FLAGS);
            $document = json_decode($json, false, self::JSON_DEPTH, self::DECODE_FLAGS);
        } catch (JsonException) {
            throw self::invalidBody();
        }

        if (! $document instanceof stdClass || ! is_array($data) || self::hasDuplicateKeys($json)) {
            throw self::invalidBody();
        }

        return new self($data, $document);
    }

    public function eventId(): string
    {
        return strtolower((string) $this->data['event_id']);
    }

    public function generatedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->data['generated_at'])->utc()->startOfSecond();
    }

    /** JSON laporan dalam bentuk kanonis (key objek terurut rekursif, urutan array dipertahankan). */
    public function reportJson(): string
    {
        return json_encode(self::canonicalize($this->document->report), self::ENCODE_FLAGS);
    }

    /** SHA-256 atas metadata stabil dan laporan; tanpa timestamp HTTP, signature, maupun received_at. */
    public function hash(): string
    {
        $semantic = ['generated_at' => $this->generatedAt()->format('Y-m-d\TH:i:s\Z')];
        foreach (self::HASHED_METADATA as $field) {
            $semantic[$field] = $field === 'event_id' ? $this->eventId() : $this->data[$field];
        }
        $semantic['report'] = $this->document->report;

        return hash('sha256', json_encode(self::canonicalize((object) $semantic), self::ENCODE_FLAGS));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            return (object) array_map(self::canonicalize(...), $properties);
        }

        return is_array($value) ? array_map(self::canonicalize(...), $value) : $value;
    }

    /** PHP diam-diam memakai nilai terakhir untuk key ganda, sehingga identitas bisa ambigu. */
    private static function hasDuplicateKeys(string $json): bool
    {
        $stack = [];
        $length = strlen($json);
        $position = 0;
        while ($position < $length) {
            $position += strcspn($json, '"{}[],', $position);
            if ($position >= $length) {
                break;
            }

            $char = $json[$position];
            if ($char === '"') {
                if (preg_match('/"(?:[^"\\\\]++|\\\\.)*+"/As', $json, $match, 0, $position) !== 1) {
                    return true;
                }
                $position += strlen($match[0]);
                $top = array_key_last($stack);
                if ($top !== null && $stack[$top]['expectKey']) {
                    $key = (string) json_decode($match[0]);
                    if (isset($stack[$top]['keys'][$key])) {
                        return true;
                    }
                    $stack[$top]['keys'][$key] = true;
                    $stack[$top]['expectKey'] = false;
                }

                continue;
            }

            if ($char === '{' || $char === '[') {
                $stack[] = ['expectKey' => $char === '{', 'object' => $char === '{', 'keys' => []];
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
            } elseif (($top = array_key_last($stack)) !== null && $stack[$top]['object']) {
                $stack[$top]['expectKey'] = true;
            }
            $position++;
        }

        return false;
    }

    private static function invalidBody(): ValidationException
    {
        return ValidationException::withMessages(['body' => ['Isi permintaan harus berupa objek JSON yang valid.']]);
    }
}
