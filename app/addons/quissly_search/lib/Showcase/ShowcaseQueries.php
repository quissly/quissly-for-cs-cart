<?php

declare(strict_types=1);

namespace Quissly\Search\Showcase;

/**
 * Showcase queries: example searches built from the store's own catalog - the search bar
 * suggestions the overlay types when the merchant has written none (port of the Quissly
 * Shopify app's: same rules, same output; the WooCommerce plugin has the same port). Each demonstrates a different ability (several conditions at once, a
 * price limit, a misspelling, a brand, a gift idea...), so the five read as a tour. Built
 * from catalog facts - no model call - and every candidate is RUN through search before it
 * is kept (ShowcaseRunner).
 *
 * Pure: unit-tested against the Shopify app's own expectations. PHP 7.4.
 *
 * Catalog facts: array{shop_name:?string, currency:?string, products: list<array{title,
 * product_type:?string, category:?string, vendor:?string, options: list<array{name, values:
 * string[]}>, min_price:?float}>}.
 */
final class ShowcaseQueries
{

    /** How many queries end up in the list. */
    const QUERY_COUNT = 5;
    /** Search calls spent validating candidates, per attempt. */
    const MAX_VALIDATION_SEARCHES = 12;
    /** A query must return at least this many products to be worth showing. */
    const MIN_RESULTS = 2;

    const COLOR_OPTION    = '/^(colou?r|ფერი|цвет)$/iu';
    const SIZE_OPTION     = '/^(size|ზომა|размер)$/iu';
    const MATERIAL_OPTION = '/^(material|fabric|მასალა|материал)$/iu';

    /** Kinds, in the order candidates are interleaved. */
    const ORDER = array('attributes', 'price_limit', 'misspelling', 'transliteration', 'brand', 'gift', 'cheapest', 'material', 'color');

    const GEORGIAN_TO_LATIN = array(
        'ა' => 'a', 'ბ' => 'b', 'გ' => 'g', 'დ' => 'd', 'ე' => 'e', 'ვ' => 'v', 'ზ' => 'z', 'თ' => 't',
        'ი' => 'i', 'კ' => 'k', 'ლ' => 'l', 'მ' => 'm', 'ნ' => 'n', 'ო' => 'o', 'პ' => 'p', 'ჟ' => 'zh',
        'რ' => 'r', 'ს' => 's', 'ტ' => 't', 'უ' => 'u', 'ფ' => 'p', 'ქ' => 'k', 'ღ' => 'gh', 'ყ' => 'q',
        'შ' => 'sh', 'ჩ' => 'ch', 'ც' => 'ts', 'ძ' => 'dz', 'წ' => 'ts', 'ჭ' => 'ch', 'ხ' => 'kh',
        'ჯ' => 'j', 'ჰ' => 'h',
    );

    /**
     * Candidate queries in order of preference, several per kind. The runner runs them
     * through search and keeps the first that return results, one per kind.
     *
     * @param array $facts Catalog facts (see the class comment).
     * @return array<int,array{kind:string,query:string}>
     */
    public static function buildCandidates(array $facts) {
        $products = array_values(
            array_filter(
                (array) ($facts['products'] ?? []),
                static function ($p) {
                    return '' !== self::clean($p['title'] ?? '');
                }
            )
        );
        if (empty($products)) {
            return [];
        }

        $georgianCount = 0;
        foreach ($products as $p) {
            $georgianCount += self::isGeorgian((string) $p['title']) ? 1 : 0;
        }
        $georgian = $georgianCount > count($products) / 2;
        $currency = self::clean($facts['currency'] ?? '');
        $shopKey = mb_strtolower(self::clean($facts['shop_name'] ?? ''));
        $kindOf  = static function ($p) {
            $type = self::clean($p['product_type'] ?? '');
            return '' !== $type ? $type : self::clean($p['category'] ?? '');
        };

        $types = array_slice(self::ranked(array_map($kindOf, $products)), 0, 4);
        $out   = [];
        $add   = static function ($kind, $query) use (&$out) {
            $q = self::clean($query);
            if ('' === $q || mb_strlen($q) > 80) {
                return;
            }
            foreach ($out as $c) {
                if (mb_strtolower($c['query']) === mb_strtolower($q)) {
                    return;
                }
            }
            $out[] = array('kind' => $kind, 'query' => $q);
        };
        $priceText = static function ($n) use ($georgian, $currency) {
            return $georgian ? "{$n} {$currency}-მდე" : "under {$n} {$currency}";
        };

        foreach ($types as $type) {
            $ofType = array_values(
                array_filter(
                    $products,
                    static function ($p) use ($kindOf, $type) {
                        return mb_strtolower($kindOf($p)) === mb_strtolower($type);
                    }
                )
            );
            $typeText     = self::lowerLatin($type);
            $optionValues = static function ($re) use ($ofType) {
                $values = [];
                foreach ($ofType as $p) {
                    foreach ((array) ($p['options'] ?? []) as $o) {
                        if (preg_match($re, self::clean($o['name'] ?? ''))) {
                            foreach ((array) ($o['values'] ?? []) as $v) {
                                $values[] = (string) $v;
                            }
                        }
                    }
                }
                return self::ranked($values);
            };
            $colors    = $optionValues(self::COLOR_OPTION);
            $sizes     = $optionValues(self::SIZE_OPTION);
            $materials = $optionValues(self::MATERIAL_OPTION);

            // Several conditions at once: colour + type + size.
            if (isset($colors[0], $sizes[0])) {
                $add('attributes', $georgian
                    ? self::lowerLatin($colors[0]) . " {$typeText} ზომა {$sizes[0]}"
                    : self::lowerLatin($colors[0]) . " {$typeText} size {$sizes[0]}");
            }
            // A price limit a real shopper would pick: just above the typical price.
            $typical = self::median(
                array_map(
                    static function ($p) {
                        return (float) ($p['min_price'] ?? 0);
                    },
                    $ofType
                )
            );
            if ($typical && '' !== $currency) {
                $add('price_limit', "{$typeText} " . $priceText(self::niceCeil($typical)));
            }

            if ($georgian) {
                $add('transliteration', self::transliterateGeorgian($typeText));
            } else {
                $add('misspelling', self::misspell($typeText));
            }

            $brands = array_values(
                array_filter(
                    self::ranked(
                        array_map(
                            static function ($p) {
                                return (string) ($p['vendor'] ?? '');
                            },
                            $ofType
                        )
                    ),
                    static function ($v) use ($shopKey) {
                        return mb_strtolower($v) !== $shopKey;
                    }
                )
            );
            if (isset($brands[0])) {
                $add('brand', "{$brands[0]} {$typeText}");
            }

            $add('cheapest', $georgian ? "ყველაზე იაფი {$typeText}" : "cheapest {$typeText}");
            if (isset($materials[0])) {
                $add('material', self::lowerLatin($materials[0]) . " {$typeText}");
            }
            if (isset($colors[0])) {
                $add('color', self::lowerLatin($colors[0]) . " {$typeText}");
            }
        }

        // A gift idea, priced from the whole catalog.
        $typicalAll = self::median(
            array_map(
                static function ($p) {
                    return (float) ($p['min_price'] ?? 0);
                },
                $products
            )
        );
        if ($typicalAll && '' !== $currency) {
            $n = self::niceCeil($typicalAll);
            $add('gift', $georgian ? "საჩუქარი {$n} {$currency}-მდე" : "gift ideas under {$n} {$currency}");
        }

        // Interleave kinds so the first few candidates already cover different abilities
        // (a stable sort: by kind order, then by the order they were built in).
        $indexed = [];
        foreach ($out as $i => $c) {
            $indexed[] = array(array_search($c['kind'], self::ORDER, true), $i, $c);
        }
        usort(
            $indexed,
            static function ($a, $b) {
                return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
            }
        );

        return array_map(
            static function ($x) {
                return $x[2];
            },
            $indexed
        );
    }

    /**
     * Keep up to QUERY_COUNT validated queries, one per kind first; only if kinds run out
     * does a second query of the same kind get in.
     *
     * @param array $validated Validated candidates, in order.
     * @param int   $count     How many.
     * @return string[]
     */
    public static function pick(array $validated, $count = self::QUERY_COUNT) {
        $picked = [];
        foreach ($validated as $i => $c) {
            if (count($picked) >= $count) {
                break;
            }
            $kinds = array_column($picked, 'kind');
            if (!in_array($c['kind'], $kinds, true)) {
                $picked[$i] = $c;
            }
        }
        foreach ($validated as $i => $c) {
            if (count($picked) >= $count) {
                break;
            }
            if (!isset($picked[$i])) {
                $picked[$i] = $c;
            }
        }
        // Keep the order they were picked in (first pass, then second).
        return array_values(array_column($picked, 'query'));
    }

    /**
     * Run candidates through search; keep those with at least MIN_RESULTS. One per kind
     * first, so the call budget is not spent on five variants of the same idea.
     *
     * @param array    $candidates Candidates (buildCandidates()).
     * @param callable $search     query => number of results (may throw).
     * @return array<int,array{kind:string,query:string}>
     */
    public static function validate(array $candidates, callable $search) {
        $accepted = [];
        $tried    = [];
        $calls    = 0;

        $attempt = static function ($i, $c) use (&$accepted, &$tried, &$calls, $search) {
            $tried[$i] = true;
            ++$calls;
            try {
                if ((int) $search($c['query']) >= self::MIN_RESULTS) {
                    $accepted[] = $c;
                }
            } catch (\Throwable $e) {
                // A failed search is just a candidate not kept.
                unset($e);
            }
        };
        $done         = static function () use (&$calls, &$accepted) {
            return $calls >= self::MAX_VALIDATION_SEARCHES || count($accepted) >= self::QUERY_COUNT;
        };
        $hasAccepted = static function ($kind) use (&$accepted) {
            return in_array($kind, array_column($accepted, 'kind'), true);
        };

        // 1. The first candidate of every kind, so a failing kind cannot use up the budget
        //    with its second and third variants before other kinds are tried.
        $firstOfKind = [];
        foreach ($candidates as $i => $c) {
            if ($done()) {
                break;
            }
            if (isset($firstOfKind[$c['kind']])) {
                continue;
            }
            $firstOfKind[$c['kind']] = true;
            $attempt($i, $c);
        }
        // 2. Second choices, only for kinds that have nothing yet.
        foreach ($candidates as $i => $c) {
            if ($done()) {
                break;
            }
            if (!isset($tried[$i]) && !$hasAccepted($c['kind'])) {
                $attempt($i, $c);
            }
        }
        // 3. Still short: anything left, even a second query of the same kind.
        foreach ($candidates as $i => $c) {
            if ($done()) {
                break;
            }
            if (!isset($tried[$i])) {
                $attempt($i, $c);
            }
        }

        return $accepted;
    }

    /**
     * A round number just above $x: 37 -> 40, 143 -> 150, 1830 -> 1900.
     *
     * @param float $x Number.
     * @return int
     */
    public static function niceCeil($x) {
        if (!($x > 0)) {
            return 0;
        }
        $step = $x < 20 ? 5 : ($x < 100 ? 10 : ($x < 500 ? 50 : ($x < 2000 ? 100 : 500)));

        return (int) (ceil($x / $step) * $step);
    }

    /**
     * A believable typo: swap two adjacent letters in the middle of the longest Latin word.
     * "hoodies" -> "hooides". Null when there is no word to misspell.
     *
     * @param string $text Text.
     * @return string|null
     */
    public static function misspell($text) {
        $words = preg_split('/\s+/u', (string) $text);
        $best  = -1;
        foreach ($words as $i => $w) {
            if (preg_match('/^[A-Za-z]{5,}$/', $w) && ($best < 0 || strlen($w) > strlen($words[$best]))) {
                $best = $i;
            }
        }
        if ($best < 0) {
            return null;
        }
        $w    = $words[$best];
        $i    = (int) floor(strlen($w) / 2);
        $typo = substr($w, 0, $i) . $w[$i + 1] . $w[$i] . substr($w, $i + 2);
        if (strtolower($typo) === strtolower($w)) {
            return null;
        }
        $words[$best] = $typo;

        return implode(' ', $words);
    }

    /**
     * Georgian typed in Latin letters, the way many shoppers search: "მაისური" -> "maisuri".
     * Null when there is nothing Georgian to convert.
     *
     * @param string $text Text.
     * @return string|null
     */
    public static function transliterateGeorgian($text) {
        if (!self::isGeorgian((string) $text)) {
            return null;
        }

        return strtr((string) $text, self::GEORGIAN_TO_LATIN);
    }

    /**
     * @param string $text Text.
     * @return bool
     */
    private static function isGeorgian($text) {
        return (bool) preg_match('/[\x{10A0}-\x{10FF}]/u', (string) $text);
    }

    /**
     * Whitespace collapsed, trimmed.
     *
     * @param mixed $text Text.
     * @return string
     */
    private static function clean($text) {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * Lowercase for Latin text; other scripts (and their caps) left alone.
     *
     * @param string $text Text.
     * @return string
     */
    private static function lowerLatin($text) {
        return preg_match('/[A-Za-z]/', (string) $text) ? mb_strtolower((string) $text) : (string) $text;
    }

    /**
     * Most common values first, case-insensitive, keeping the commonest spelling (ties keep
     * first-seen order).
     *
     * @param string[] $values Values.
     * @return string[]
     */
    private static function ranked(array $values) {
        $groups = [];
        $order  = 0;
        foreach ($values as $raw) {
            $v = self::clean($raw);
            if ('' === $v) {
                continue;
            }
            $key = mb_strtolower($v);
            if (!isset($groups[$key])) {
                $groups[$key] = array('count' => 0, 'first' => $order++, 'spellings' => []);
            }
            ++$groups[$key]['count'];
            if (!isset($groups[$key]['spellings'][$v])) {
                $groups[$key]['spellings'][$v] = array(0, count($groups[$key]['spellings']));
            }
            ++$groups[$key]['spellings'][$v][0];
        }
        $groups = array_values($groups);
        usort(
            $groups,
            static function ($a, $b) {
                return $b['count'] <=> $a['count'] ?: $a['first'] <=> $b['first'];
            }
        );

        return array_map(
            static function ($g) {
                $spellings = $g['spellings'];
                uasort(
                    $spellings,
                    static function ($a, $b) {
                        return $b[0] <=> $a[0] ?: $a[1] <=> $b[1];
                    }
                );
                return (string) array_key_first($spellings);
            },
            $groups
        );
    }

    /**
     * Median of the positive values, or null.
     *
     * @param float[] $xs Values.
     * @return float|null
     */
    private static function median(array $xs) {
        $s = array_values(
            array_filter(
                $xs,
                static function ($x) {
                    return $x > 0;
                }
            )
        );
        if (empty($s)) {
            return null;
        }
        sort($s);
        $mid = (int) floor(count($s) / 2);

        return count($s) % 2 ? $s[$mid] : ($s[$mid - 1] + $s[$mid]) / 2;
    }

    private function __construct()
    {
    }
}
