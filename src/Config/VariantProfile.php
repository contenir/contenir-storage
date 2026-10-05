<?php

declare(strict_types=1);

namespace Contenir\Storage\Config;

use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use InvalidArgumentException;

use function array_key_exists;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;
use function trim;

/**
 * One art-directed image profile, declared in a single place.
 *
 * A profile (e.g. `card`, `hero`) carries a responsive `dimensions` ladder plus
 * the shared fit / quality / formats and the front-end `sizes` attribute. It
 * compiles to the flat {@see Variant} list the generator materialises AND
 * exposes the render config the front-end consumes — so the family is no longer
 * duplicated between the generation catalogue and the front-end profiles.
 *
 * Each ladder rung is a dimension string — `WxH`, `Wx` (auto height) or `xH`
 * (auto width, generator-only) — given as a bare value or a `"WxH" => overrides`
 * map entry. Expanded variant names are `"<profile>-<width>"` so existing
 * sibling keys are reproduced exactly (no regeneration).
 *
 * @mago-expect lint:cyclomatic-complexity One validation branch per documented ladder rule; splitting is a follow-up.
 */
final class VariantProfile
{
    /**
     * @param list<Variant> $variants Expanded, in ladder order.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $sizes,
        public readonly bool $isPreview,
        public readonly array $variants,
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException If the declaration is malformed.
     *
     * @mago-expect analysis:mixed-assignment Profile declarations are untyped config; each rung is validated here.
     */
    public static function fromArray(string $name, array $config): self
    {
        $dimensions = $config['dimensions'] ?? null;
        if (! is_array($dimensions) || [] === $dimensions) {
            throw new InvalidArgumentException(sprintf(
                'Profile "%s" must declare a non-empty "dimensions" ladder.',
                $name,
            ));
        }

        $defaultFit     = self::parseFit((string) ($config['fit'] ?? 'contain'), $name);
        $defaultQuality = self::optionalInt($config['quality'] ?? null);
        $defaultFormats = self::parseFormats($config['formats'] ?? []);
        $sizes          = (string) ($config['sizes'] ?? '');
        $isPreview      = ($config['role'] ?? null) === 'preview';

        $variants = [];
        $seen     = [];
        foreach ($dimensions as $key => $value) {
            /**
             * A bare dimension string, or a `"WxH" => [overrides]` map entry.
             */
            $spec     = is_string($value) ? $value : (string) $key;
            $override = is_array($value) ? $value : [];

            [$width, $height] = self::parseDimension($spec, $name);
            $fit = null === ($override['fit'] ?? null)
                ? $defaultFit
                : self::parseFit((string) $override['fit'], $name);
            $quality = self::optionalInt($override['quality'] ?? null) ?? $defaultQuality;
            $formats = null === ($override['formats'] ?? null)
                ? $defaultFormats
                : self::parseFormats($override['formats']);

            if (VariantFit::Contain !== $fit && ($width <= 0 || $height <= 0)) {
                throw new InvalidArgumentException(sprintf(
                    'Profile "%s" rung "%s" uses fit "%s", which requires both a width and a height.',
                    $name,
                    $spec,
                    $fit->value,
                ));
            }

            if (! $isPreview && $width <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'Profile "%s" rung "%s" has no width; a front-end profile needs a width for the srcset descriptor.',
                    $name,
                    $spec,
                ));
            }

            $variantName = $name . '-' . ($width > 0 ? (string) $width : "x{$height}");
            if (array_key_exists($variantName, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'Profile "%s" produces variant "%s" more than once; each rung must be unique.',
                    $name,
                    $variantName,
                ));
            }
            $seen[$variantName] = true;

            $variants[] = new Variant($variantName, $width, $height, $fit, $formats, $quality);
        }

        return new self($name, $sizes, $isPreview, $variants);
    }

    private static function optionalInt(mixed $value): ?int
    {
        return null === $value ? null : (int) $value;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function parseDimension(string $spec, string $name): array
    {
        $m = [];
        if (1 !== preg_match('/^(\d*)\s*x\s*(\d*)$/', trim($spec), $m)) {
            throw new InvalidArgumentException(sprintf(
                'Profile "%s" dimension "%s" must be <width>x<height>, <width>x or x<height>.',
                $name,
                $spec,
            ));
        }

        $width  = (int) ($m[1] ?? 0);
        $height = (int) ($m[2] ?? 0);
        if ($width <= 0 && $height <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Profile "%s" dimension "%s" must set at least one of width or height.',
                $name,
                $spec,
            ));
        }

        return [$width, $height];
    }

    private static function parseFit(string $fit, string $name): VariantFit
    {
        return match (strtolower($fit)) {
            'cover'   => VariantFit::Cover,
            'contain' => VariantFit::Contain,
            'fill'    => VariantFit::Fill,
            default   => throw new InvalidArgumentException(sprintf(
                'Profile "%s" has unknown fit "%s" (expected: cover, contain, fill).',
                $name,
                $fit,
            )),
        };
    }

    /**
     * @return list<string>
     *
     * @mago-expect analysis:mixed-assignment Format lists are untyped config; each entry is cast to string.
     */
    private static function parseFormats(mixed $formats): array
    {
        if (! is_array($formats)) {
            return [];
        }

        $out = [];
        foreach ($formats as $format) {
            $out[] = strtolower(trim((string) $format, characters: " \t\n\r\0\x0B."));
        }

        return $out;
    }
}
