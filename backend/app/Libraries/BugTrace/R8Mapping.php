<?php

namespace App\Libraries\BugTrace;

/**
 * Android R8 / ProGuard mapping.txt (FR-BT-ING-04): de-obfuscates Java/Kotlin
 * stack frames.
 *
 *   com.worksuite.mail.FolderList -> a.b.c:
 *       java.lang.String title -> a
 *       12:15:void open(int):40:43 -> b      (obfuscated lines 12–15 = original 40–43)
 *       void close() -> c
 *
 * Inlined methods produce several entries for the same obfuscated name and
 * line range; the first one is the innermost original method.
 */
final class R8Mapping
{
    /** @var array<string, string> obfuscated class → original */
    private array $classes = [];

    /** @var array<string, list<array{name:string, from:int, to:int, origFrom:int, origTo:int, class:string}>> "obfClass#obfMethod" → entries */
    private array $methods = [];

    public static function parse(string $text): self
    {
        $m   = new self();
        $cur = null;
        $currentOrig = null;
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($line[0] !== ' ' && $line[0] !== "\t") {
                if (preg_match('/^(\S+) -> (\S+):$/', $line, $c)) {
                    $m->classes[$c[2]] = $c[1];
                    $cur               = $c[2];
                    $currentOrig       = $c[1];
                } else {
                    $cur = null;
                }

                continue;
            }
            if ($cur === null) {
                continue;
            }
            // [a:b:]type name(args)[:c[:d]] -> obf     (fields have no parentheses and are ignored)
            if (! preg_match('/^\s+(?:(\d+):(\d+):)?\S+ ([^\s(]+)\([^)]*\)(?::(\d+)(?::(\d+))?)? -> (\S+)$/', $line, $x)) {
                continue;
            }
            $from     = $x[1] !== '' ? (int) $x[1] : 0;
            $to       = $x[2] !== '' ? (int) $x[2] : 0;
            $origFrom = isset($x[4]) && $x[4] !== '' ? (int) $x[4] : $from;
            $origTo   = isset($x[5]) && $x[5] !== '' ? (int) $x[5] : ($origFrom + ($to - $from));
            $name     = $x[3];
            $class    = $currentOrig;
            if (($dot = strrpos($name, '.')) !== false) { // inlined from another class: "com.x.Other.method"
                $class = substr($name, 0, $dot);
                $name  = substr($name, $dot + 1);
            }
            $m->methods[$cur . '#' . $x[6]][] = ['name' => $name, 'from' => $from, 'to' => $to, 'origFrom' => $origFrom, 'origTo' => $origTo, 'class' => $class];
        }

        return $m;
    }

    public function originalClass(string $obfuscated): ?string
    {
        return $this->classes[$obfuscated] ?? null;
    }

    /**
     * @return list<array{class: string, function: string, line: ?int}> innermost first (inlined frames expanded)
     */
    public function retrace(string $class, string $method, ?int $line): array
    {
        $origClass = $this->classes[$class] ?? null;
        if ($origClass === null) {
            return [];
        }
        $entries = $this->methods[$class . '#' . $method] ?? [];
        $hits    = [];
        foreach ($entries as $e) {
            if ($line !== null && $e['from'] !== 0 && ($line < $e['from'] || $line > $e['to'])) {
                continue;
            }
            $orig = null;
            if ($line !== null && $e['from'] !== 0) {
                $orig = $e['origFrom'] === $e['origTo'] ? $e['origFrom'] : $e['origFrom'] + ($line - $e['from']);
            } elseif ($e['origFrom'] !== 0) {
                $orig = $e['origFrom'];
            }
            $hits[] = ['class' => $e['class'], 'function' => $e['name'], 'line' => $orig];
        }
        if ($hits === []) {
            // Method unknown (e.g. not renamed): keep the name, restore the class.
            return [['class' => $origClass, 'function' => $method, 'line' => $line]];
        }
        if ($line === null) {
            // Ambiguous overloads without a line: one candidate per distinct name.
            $seen = [];
            $hits = array_values(array_filter($hits, static function ($h) use (&$seen) {
                $k = $h['class'] . '#' . $h['function'];
                if (isset($seen[$k])) {
                    return false;
                }

                return $seen[$k] = true;
            }));
        }

        return $hits;
    }
}
