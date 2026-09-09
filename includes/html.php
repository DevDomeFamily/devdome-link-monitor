<?php
/**
 * One HTML tokenizer shared by the scanner, the post-save verification and the Edit/Unlink
 * rewrites, so all four agree on what a link is. It is a small state machine, not a regex
 * over tags: comments (block delimiters included), CDATA, and the browser's raw-text /
 * RCDATA elements (script, style, textarea, title, iframe, xmp, noembed, noframes, noscript,
 * plaintext) are consumed as text, so nothing inside them is ever a tag - neither a fake
 * <a href> nor a fake </a>. A ">" inside a quoted attribute does not end a tag; attribute
 * values may be double-quoted, single-quoted or bare and are entity-decoded exactly once
 * with the HTML5 table, the way a browser decodes them.
 */

defined('ABSPATH') || exit;

/**
 * HTML void elements: self-contained by definition. For every other element a "/" before ">"
 * is ignored by browsers ("<a href=x />hello</a>" is a real anchor, "<script/>" still opens a
 * script that runs to "</script>"), and it is ignored here too.
 */
function devdlink_html_void_elements()
{
    return array('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr');
}

/** Elements whose content the browser treats as text until their own end tag. */
function devdlink_html_raw_text_elements()
{
    return array('script', 'style', 'textarea', 'title', 'iframe', 'xmp', 'noembed', 'noframes', 'noscript');
}

/**
 * Tokenize an HTML fragment into start and end tags. Everything else (text, comments, raw
 * text) produces no token.
 *
 * @param string $html
 * @return array[] start: type,name,attrs,raw,start,end,self  /  end: type,name,start,end
 */
function devdlink_html_tokens($html)
{
    $out  = array();
    $html = (string) $html;
    $len  = strlen($html);
    $pos  = 0;
    $raw_text = array_flip(devdlink_html_raw_text_elements());
    $void     = array_flip(devdlink_html_void_elements());
    while ($pos < $len && ($lt = strpos($html, '<', $pos)) !== false) {
        $pos = $lt + 1;
        if ($pos >= $len) {
            break;
        }
        $c = $html[$pos];
        if ($c === '!') {
            if (substr($html, $lt, 4) === '<!--') {
                $e   = strpos($html, '-->', $lt + 4);
                $pos = ($e === false) ? $len : $e + 3;
                continue;
            }
            if (substr($html, $lt, 9) === '<![CDATA[') {
                $e   = strpos($html, ']]>', $lt + 9);
                $pos = ($e === false) ? $len : $e + 3;
                continue;
            }
            $e   = strpos($html, '>', $pos);
            $pos = ($e === false) ? $len : $e + 1;
            continue;
        }
        if ($c === '?') {
            $e   = strpos($html, '>', $pos);
            $pos = ($e === false) ? $len : $e + 1;
            continue;
        }
        if ($c === '/') {
            // End tag: "</name ...>". Anything else after "</" is a bogus comment.
            $n = $pos + 1;
            if ($n >= $len || !ctype_alpha($html[$n])) {
                $e   = strpos($html, '>', $pos);
                $pos = ($e === false) ? $len : $e + 1;
                continue;
            }
            $s = $n;
            while ($n < $len && (ctype_alnum($html[$n]) || $html[$n] === '-' || $html[$n] === ':')) {
                $n++;
            }
            $name = strtolower(substr($html, $s, $n - $s));
            $e    = strpos($html, '>', $n);
            $end  = ($e === false) ? $len : $e + 1;
            $out[] = array('type' => 'end', 'name' => $name, 'start' => $lt, 'end' => $end);
            $pos = $end;
            continue;
        }
        if (!ctype_alpha($c)) {
            continue;
        }
        $n = $pos;
        while ($n < $len && (ctype_alnum($html[$n]) || $html[$n] === '-' || $html[$n] === ':')) {
            $n++;
        }
        $name  = strtolower(substr($html, $pos, $n - $pos));
        $i     = $n;
        $attrs = array();
        $raw   = array();
        $self  = isset($void[$name]); // never decided by a "/" - see devdlink_html_void_elements()
        while ($i < $len) {
            $ch = $html[$i];
            if ($ch === '>') {
                break;
            }
            if ($ch === '/') {
                $i++;
                continue;
            }
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            $s = $i;
            while ($i < $len && !ctype_space($html[$i]) && $html[$i] !== '=' && $html[$i] !== '>' && $html[$i] !== '/') {
                $i++;
            }
            if ($i === $s) {
                $i++;
                continue;
            }
            $an = strtolower(substr($html, $s, $i - $s));
            $j  = $i;
            while ($j < $len && ctype_space($html[$j])) {
                $j++;
            }
            if ($j < $len && $html[$j] === '=') {
                $j++;
                while ($j < $len && ctype_space($html[$j])) {
                    $j++;
                }
                if ($j < $len && ($html[$j] === '"' || $html[$j] === "'")) {
                    $q  = $html[$j];
                    $vs = $j + 1;
                    $ve = strpos($html, $q, $vs);
                    if ($ve === false) {
                        $ve = $len;
                    }
                    $val   = substr($html, $vs, $ve - $vs);
                    $i     = min($len, $ve + 1);
                    $entry = array('start' => $vs, 'end' => $ve, 'quote' => $q);
                } else {
                    $vs = $j;
                    $ve = $vs;
                    while ($ve < $len && !ctype_space($html[$ve]) && $html[$ve] !== '>') {
                        $ve++;
                    }
                    $val   = substr($html, $vs, $ve - $vs);
                    $i     = $ve;
                    $entry = array('start' => $vs, 'end' => $ve, 'quote' => '');
                }
                if (!isset($attrs[$an])) {
                    // Decoded ONCE, here, with the HTML5 entity table. Nothing downstream decodes
                    // again, so "&amp;amp;" stays the literal "&amp;" the browser sees.
                    $attrs[$an] = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $raw[$an]   = $entry;
                }
            } else {
                if (!isset($attrs[$an])) {
                    $attrs[$an] = '';
                    $raw[$an]   = array('start' => $i, 'end' => $i, 'quote' => '');
                }
                $i = $j;
            }
        }
        $end   = ($i < $len) ? $i + 1 : $len;
        $out[] = array('type' => 'start', 'name' => $name, 'attrs' => $attrs, 'raw' => $raw, 'start' => $lt, 'end' => $end, 'self' => $self);
        $pos   = $end;
        if ($name === 'plaintext') {
            break; // everything after <plaintext> is text until the end of the document
        }
        if (isset($raw_text[$name]) && !$self) {
            // Raw text: consumed up to and including the element's OWN end tag. A "</a>" or an
            // "<a href>" inside it is text to the browser and is text here.
            $e = devdlink_html_find_end_tag($html, $name, $end);
            if ($e === false) {
                break;
            }
            $gt  = strpos($html, '>', $e);
            $pos = ($gt === false) ? $len : $gt + 1;
        }
    }
    return $out;
}

/**
 * Byte offset of the next real end tag "</name>" (name followed by whitespace or ">"), or false.
 *
 * @param string $html
 * @param string $name lowercase tag name
 * @param int    $from
 * @return int|false
 */
function devdlink_html_find_end_tag($html, $name, $from)
{
    if (preg_match('~</' . preg_quote($name, '~') . '(?=[\s>])~i', $html, $m, PREG_OFFSET_CAPTURE, $from)) {
        return (int) $m[0][1];
    }
    return false;
}

/**
 * The wanted tags of an HTML fragment, each with its attributes and byte offsets; for <a>
 * with an end tag also the inner range and the offset after "</a>". The end tag is the next
 * "</a>" TOKEN after the start tag, so "</a>" inside a comment, a script or a textarea can
 * never close an anchor.
 *
 * @param string   $html
 * @param string[] $names lowercase tag names to report (default a + img)
 * @return array[] name, attrs, raw, start, end, inner_start, inner_end, close_end
 */
function devdlink_html_tags($html, $names = array('a', 'img'))
{
    $want   = array_flip(array_map('strtolower', (array) $names));
    $tokens = devdlink_html_tokens($html);
    $count  = count($tokens);
    $out    = array();
    for ($k = 0; $k < $count; $k++) {
        $t = $tokens[$k];
        if ($t['type'] !== 'start' || !isset($want[$t['name']])) {
            continue;
        }
        $tag = array(
            'name'        => $t['name'],
            'attrs'       => $t['attrs'],
            'raw'         => $t['raw'],
            'start'       => $t['start'],
            'end'         => $t['end'],
            'inner_start' => null,
            'inner_end'   => null,
            'close_end'   => null,
        );
        if ($t['name'] === 'a' && !$t['self']) {
            for ($m = $k + 1; $m < $count; $m++) {
                if ($tokens[$m]['type'] === 'end' && $tokens[$m]['name'] === 'a') {
                    $tag['inner_start'] = $t['end'];
                    $tag['inner_end']   = $tokens[$m]['start'];
                    $tag['close_end']   = $tokens[$m]['end'];
                    break;
                }
                if ($tokens[$m]['type'] === 'start' && $tokens[$m]['name'] === 'a') {
                    break; // a new anchor before this one closed: the first has no end tag
                }
            }
        }
        $out[] = $tag;
    }
    return $out;
}

/**
 * Apply byte-range edits (start, end, replacement) to a string, last range first so earlier
 * offsets stay valid. Overlapping ranges after the first are skipped.
 *
 * @param string $html
 * @param array  $edits each array($start, $end, $replacement)
 * @return string
 */
function devdlink_html_apply_edits($html, $edits)
{
    usort($edits, function ($a, $b) {
        return $b[0] - $a[0];
    });
    $floor = PHP_INT_MAX;
    foreach ($edits as $e) {
        if ($e[1] > $floor) {
            continue; // overlaps a range already replaced
        }
        $html  = substr($html, 0, $e[0]) . $e[2] . substr($html, $e[1]);
        $floor = $e[0];
    }
    return $html;
}

/**
 * Does an attribute value denote the tracked URL? Either it is one of the literal forms, or the
 * caller's matcher (which resolves the value the way the scanner does, relative forms included)
 * says so.
 *
 * @param string        $value   decoded, trimmed attribute value
 * @param string[]      $forms
 * @param callable|null $matcher function (string $value): bool
 * @return bool
 */
function devdlink_html_value_matches($value, $forms, $matcher = null)
{
    if (in_array($value, $forms, true)) {
        return true;
    }
    return $matcher !== null && is_callable($matcher) && $value !== '' && (bool) call_user_func($matcher, $value);
}

/**
 * Rewrite the URL of every <a href> / <img src> whose (decoded) value is one of $forms.
 * ONLY the attribute value changes: the same string in a block comment, a data attribute, a
 * <code> element, visible text or a raw-text element is left alone. A bare value is written
 * back quoted.
 *
 * @param string   $html
 * @param string[] $forms literal forms of the old URL (absolute, protocol-relative, root-relative)
 * @param string   $new_url
 * @return array array('html' => string, 'count' => int)
 */
function devdlink_rewrite_url_in_html($html, $forms, $new_url, $matcher = null)
{
    $forms = array_values(array_unique(array_map('strval', (array) $forms)));
    $edits = array();
    foreach (devdlink_html_tags($html) as $t) {
        $attr = ($t['name'] === 'a') ? 'href' : 'src';
        if (!isset($t['raw'][$attr]) || !devdlink_html_value_matches(trim((string) $t['attrs'][$attr]), $forms, $matcher)) {
            continue;
        }
        $r = $t['raw'][$attr];
        $q = $r['quote'] !== '' ? $r['quote'] : '"';
        $v = str_replace(array('&', $q), array('&amp;', $q === '"' ? '&quot;' : '&#039;'), (string) $new_url);
        $edits[] = array($r['start'], $r['end'], $r['quote'] !== '' ? $v : $q . $v . $q);
    }
    return array('html' => $edits ? devdlink_html_apply_edits($html, $edits) : $html, 'count' => count($edits));
}

/**
 * Remove every <a href> whose (decoded) value is one of $forms, keeping the anchor's inner
 * markup (comments and raw-text elements inside it included, untouched). Anchors without an
 * end tag are left alone, and therefore still counted by the scanner.
 *
 * @param string   $html
 * @param string[] $forms
 * @return array array('html' => string, 'count' => int)
 */
function devdlink_unlink_in_html($html, $forms, $matcher = null)
{
    $forms = array_values(array_unique(array_map('strval', (array) $forms)));
    $edits = array();
    foreach (devdlink_html_tags($html, array('a')) as $t) {
        if ($t['close_end'] === null || !isset($t['raw']['href']) || !devdlink_html_value_matches(trim((string) $t['attrs']['href']), $forms, $matcher)) {
            continue;
        }
        $edits[] = array($t['start'], $t['close_end'], substr($html, $t['inner_start'], $t['inner_end'] - $t['inner_start']));
    }
    return array('html' => $edits ? devdlink_html_apply_edits($html, $edits) : $html, 'count' => count($edits));
}
