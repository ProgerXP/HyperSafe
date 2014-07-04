<?php
/*
  HyperSafe - Deep HTML Filter    | https://github.com/ProgerXP/HyperSafe
  in public domain | by Proger_XP | http://proger.me
*/

/*
  HyperSafe is a highly customizable deep HTML filter that lets you avoid potentially
  unsafe/unwanted markup and even sanitize individual attributes like CSS 'style'.

  First, HyperSafe escapes all < and > symbols in the input string, also escaping &
  if it's not part of a valid HTML symbol (htmlspecialchars() without $double_encode).
  Then it goes through all escaped &lt;tags&gt; to unescape valid tags for which
  proper nesting is maintained (no <a><b></a></b>).

  1. It adds tags to the stack when they are opened unless it's a single tag (<img>)
  2. Single tags are processed immediately without adding them to the stack
  3. If a closing tag is encountered and is present in the stack - it's taken off
     and processed along with the opening tag
  4. If it's not present and it's a single tag - it's left escaped and unprocessed
     since single tags can't be closed (</img>)
  5. If not present and not single - stack is searched for corresponding opening
     tag, if found - all those opened after it are discarded (kept escaped) and
     now-matching tags are processed as usual; if not found - stack is unchanged
     but the closing tag is ignored (kept escaped)

  If a tag is not present in $tags it's never looked at/added to stack (kept escaped).

  Processing means:
  1. Removing disallowed attributes that are not listed in $tags
  2. Checking the rest with $attributes, if they have checker defined
  3. Removing mismatching attributes that didn't pass step #2
  4. Checking if required attributes (!attr) are missing - if so keeping the opening
     and closing tags escaped but removing them from stack so nesting isn't broken
  5. Finally outputting unescaped, proper HTML tag if everything is fine

  This ensures that all markup is untrusted and escaped by default and that only
  trusted, validated markup passes through to the final document.

  Simplest usage:

    HyperSafe::defaultClean('<p><b><i>Dirty</i> markup</b>.</p>');
      //=> '<p>Dirty markup.</p>'

  If you want to tweak settings and/or check warnings:

    $hs = new HyperSafe;
    $hs->lineBreaks = "\r\n";
    $hs->tags['iframe'] = array('!src url');
    echo $hs->clean("<article>\n<iframe src="http://google.com"></iframe>\n</article>");
    $
*/

class HyperSafeEncodingError extends Exception {
  static function checkUTF8($str) {
    preg_match('/./u', $str);
    self::checkLast();
  }

  static function checkLast() {
    if (preg_last_error() != PREG_NO_ERROR) { throw new self; }
  }

  function __construct() {
    $code = preg_last_error();
    parent::__construct("PCRE error; preg_last_error() returned $code - make sure".
                        "HyperSafe input was encoded in UTF-8.");
  }
}

class HyperSafe {
  // Automatically set to ENT_HTML5 if htmlspecialchars() and the likes support
  // it (PHP 5.4+) or to 0 otherwise.
  static $html5Flag = 0;

  /***
    Allowed CSS properties and their values.
   ***
    Key is ignored.
    Value = string of the same format as $tags members below: attr[ checker].

    If checker is present will check/change the value of this CSS rule and
    remove mismatching rules. attr may contain '*' wildcards though they slow
    down processing.
   ***/

  public $styles = array(
    'color', 'background*', 'border*', 'box-shadow', 'clear', 'display', 'float',
    'height', 'overflow*', 'padding*', 'width', 'vertical-align', 'align-*',
    'flex*', 'justify-content', 'margin*', 'max-height', 'max-width', 'min-height',
    'min-width', 'order', 'letter-spacing', 'line-height', 'tab-size', 'text-*',
    'white-space', 'word-*', 'text-*', 'font*', 'direction', 'unicode-bidi',
    'border*', 'caption-side', 'empty-cells', 'table-layout', 'list-style-*',
    'animation*', 'backface-visibility', 'perspective*', 'transform*',
    'transition*', 'box-sizing', 'cursor', 'icon', 'outline*', 'resize',
    'column*', 'page-break-*',
  );

  /***
    Checkers (sanitizers) for HTML attributes and CSS styles.
   ***
    Key = name that is used in $tags. Value = one of the following:
    * string starting with ~ is a full regexp: ~foo?~u ('u' is always added)
    * other string - treated as ~^VALUE~u (matched against ginning)
    * Closure or callable array - function ($value, HyperSafe $self) returning
      true to accept, false to deny or a string to accept but modify the value
    * array - all members are tested as explained above and if any fails the
      entire attribute match fails

    Attributes with non-matching values will be removed from the tag:
    <img src="javascript:foo"> -> <img> -> &lt;img&gt; (with '!src')

    Also defines checks for $styles (they are shared with $tags).
   ***/

  public $checks = array(
    // Work around PHP inability to assign Closures as default values. If true
    // is set to $this->cleanCSS() call in constructor.
    'css'         => true,
    'filename'    => '[\w\- .]+$',
    'lang2'       => '\w\w$',
    'mime'        => '[\w-]+/[\w-]+$',
    'datetime'    => '\d\d\d\d-\d\d-\d\dT\d\d:\d\d:\d\d\w*$',
    'url'         => array('((https?|ftp)://|/[^\\\\/]|#)', '[^\\\\]+$'),
    'imgurl'      => array('((https?|ftp)://|/[^\\\\/]|data:image/\w+;base64,)', '[^\\\\]+$'),
    'map'         => '#[\w\- .]+$',
  );

  /***
    Allowed HTML tags and their attributes.
   ***
    Key = tag name (lower case), if beings with '.' signifies a single tag (img,
    hr, etc.). If '' - sets global attributes for all tags.

    Value = array with string members like:

       [!]ATTR[ CHECKER]

    If value is a string then it's an alias of another tag (output tag is changed);
    that other tag can be also an alias, etc. Empty array simply allows the tag
    itself but without any attributes. Input attributes that are not listed here
    will be removed from the output.

    Legend:
    * ! - optional, if present means that this attribute is required and if
      not present in the tag renders the tag invalid (such tags are quoted)
    * ATTR - name of the attribute, may contain '*' (e.g. 'data-*')
    * CHECKER - if present refers to $this->checks item used to sanitize
      this attribute's value; if omitted the value is not checked which might
      be risky
   ***/

  public $tags = array(
    // As an optimization, '' key must be always defined (even if as array()) and
    // must not contain !required attributes (they are ignored).
    ''            => array('class', 'dir', 'id', 'lang lang2', 'style css', 'title', 'data-*'),
    'a'           => array('download filename', '!href url', 'hreflang lang2', 'rel',
                           'target filename', 'target filename', 'type mime'),
    'abbr'        => array(),
    'acronym'     => 'abbr',
    'address'     => array(),
    '.area'       => array('alt', '!coords', 'download filename', '!href url', 'hreflang lang2',
                           'rel', 'shape filename', 'target filename', 'type mime'),
    'article'     => array(),
    'aside'       => array(),
    'audio'       => array('autoplay', 'controls', 'loop', 'muted', 'preload', '!src url'),
    'b'           => 'strong',
    'bdi'         => array(),
    'bdo'         => array('!dir'),
    'big'         => array(),
    'blockquote'  => array('cite url'),
    '.br'         => array(),
    'caption'     => array('align'),
    'center'      => array(),
    'cite'        => array(),
    'code'        => array(),
    '.col'        => array('align', 'span', 'valign', 'width'),
    'colgroup'    => array('align', 'span', 'valign', 'width'),
    'dd'          => array(),
    'del'         => array('cite url', 'datetime datetime'),
    'details'     => array('open'),
    'dfn'         => array(),
    'dialog'      => array('open'),
    'div'         => array('align'),
    'ol'          => array(),
    'dt'          => array(),
    'em'          => array(),
    'fieldset'    => array('disabled'),
    'figcaption'  => array(),
    'figure'      => array(),
    'footer'      => array(),
    'h1'          => array('align'),
    'h2'          => array('align'),
    'h3'          => array('align'),
    'h4'          => array('align'),
    'h5'          => array('align'),
    'h6'          => array('align'),
    'header'      => array(),
    '.hr'         => array('align', 'width'),
    'i'           => 'em',
    '.img'        => array('align', 'alt', 'height', '!src imgurl', 'usemap map', 'width'),
    '.input'      => array('align', 'disabled', 'placeholder', 'readonly', 'size', 'width'),
    'ins'         => array('cite url', 'datetime datetime'),
    'kbd'         => array(),
    'legend'      => array('align'),
    'li'          => array('type', 'value'),
    'main'        => array(),
    'map'         => array('!name filename'),
    'mark'        => array(),
    'meter'       => array('high', 'low', 'max', 'min', 'optimum', 'value'),
    'nav'         => array(),
    'ol'          => array('reversed', 'start', 'type'),
    'p'           => array('align'),
    'pre'         => array('width'),
    '.progress'   => array('!max', '!value'),
    'q'           => array('cite url'),
    'rp'          => array(),
    'rt'          => array(),
    'ruby'        => array(),
    's'           => 'del',
    'samp'        => array(),
    'section'     => array(),
    'small'       => array(),
    '.source'     => array('src url', 'type mime'),
    'span'        => array(),
    'strike'      => 's',
    'strong'      => array(),
    'sub'         => array(),
    'summary'     => array(),
    'sup'         => array(),
    'table'       => array('align', 'rules', 'sortable', 'width'),
    'tbody'       => array('align', 'valign'),
    'td'          => array('align', 'colspan', 'height', 'rowspan', 'valign', 'width'),
    'textarea'    => array('cols', 'disabled', 'placeholder', 'readonly', 'rows', 'wrap'),
    'tfoot'       => array('align', 'valign'),
    'th'          => array('align', 'colspan', 'height', 'rowspan', 'valign', 'width'),
    'thead'       => array('align', 'valign'),
    'time'        => array('datetime datetime'),
    'tr'          => array('align', 'valign'),
    '.track'      => array('default', 'kind', 'label', '!src url', 'srclang lang2'),
    'tt'          => 'kbd',
    'u'           => array(),
    'ul'          => array('type'),
    'var'         => array(),
    'video'       => array('autoplay', 'controls', 'height', 'loop', 'poster url',
                           'preload', '!src url', 'width'),
    '.wbr'        => array(),
  );

  // For clean(). If null - leaves existing line feeds as is. Otherwise replaces
  // all \r?\n and \r (Mac) with this string.
  public $lineBreaks = "\n";

  // If set <!-- ... --> won't be removed. Note that they're not sanitized.
  // If input has unterminated comment it will be automatically closed (but its
  // contents will be unprocessed).
  public $keepComments = false;

  // Original input HTML string. Read-only, set by clean(); writing does nothing.
  public $input = '';

  // Output HTML string transformed at each stage. Returned by clean().
  public $output;

  // array('msg', opening = $stackItem/null, closing = $token/null, $tokenIndex).
  // Not cleared between clean() calls on the same object (accumulated).
  //
  // If set to false no messages are logged (might be useful for huge documents
  // with lots of errors).
  protected $warnings = array();

  // array(array($tag, $tokenArray, $shift), ...) - last opened tag goes first.
  protected $stack;

  // Array of arrays (<tag ...> and </tag>) with members (pos = byte offsets):
  // 0. array('&lt;/full tag /&gt;', pos)
  // 1. array('/' or '', pos)
  // 2. array('full', pos)
  // 3. array(' tag ' or '', pos)
  // 3. array('/' or '', pos)
  protected $tokens;
  // Currently processing index in $tokens.
  protected $index;
  // Integer indicating offset correction for $tokens[$index].
  protected $shift;

  // Should target "-wrapped attributes: <img src="$VALUE">.
  static function encodeAttribute($value) {
    return htmlspecialchars($value, ENT_COMPAT | static::$html5Flag, 'utf-8');
  }

  // Should turn all possible &entities; to symbols (', " and others).
  static function decodeAttribute($value) {
    // html_entity_decode() only replaces &apos; when in ENT_HTML5 mode.
    static::$html5Flag or $value = str_replace('&apos;', "'", $value);
    return html_entity_decode($value, ENT_QUOTES | static::$html5Flag, 'utf-8');
  }

  // Allows '*', '?' and also shell patterns (e.g. [ ]) but should be okay
  // for matching attribute names that contain alphanumeric symbols anyway.
  static function match($wildcard, $str) {
    return fnmatch($wildcard, $str, FNM_NOESCAPE | FNM_PATHNAME | FNM_PERIOD);
  }

  static function defaultClean($html) {
    return static::make()->clean($html);
  }

  // (new Class)->access stub for PHP 5.4-.
  static function make() {
    return new static;
  }

  function __construct() {
    $css = &$this->checks['css'];
    $css === true and $css = function ($value, $self) {
      $value = $self->cleanCSS($value);
      return $value === '' ? false /* to remove empty attribute */ : $value;
    };
  }

  // $stack = $this->stack format. $token = $this->tokens format.
  function warn($msg, $track = true) {
    if ($this->warnings !== false) {
      if ($track) {
        $stack = reset($this->stack);
        $token = $this->tokens[$this->index];
      } else {
        $stack = $token = null;
      }

      $this->warnings[] = array($msg, $stack, $token, $this->index);
    }
    // Must return null - used as return $this->warn('error');
  }

  // Returns more stable and public version of $this->warnings.
  // 'pos' keys refer to byte offsets in $this->input.
  // Returned strings *will* contain HTML so escape it properly on the output.
  function warnings() {
    $func = function ($item) {
      if ($opener = &$item[1]) {
        $opener = array(
          'tagName'   => $opener[1][2][0],
          'tag'       => $opener[1][0][0],
          'pos'       => $opener[1][0][1],
          'token'     => $opener[1],
          'stackItem' => $opener,
        );
      }

      if ($closer = &$item[2]) {
        $closer = array(
          'tagName'   => $closer[2][0],
          'tag'       => $closer[0][0],
          'pos'       => $closer[0][1],
          'token'     => $closer,
        );
      }

      list($msg, , , $tokenIndex) = $item;
      return compact('msg', 'tokenIndex', 'opener', 'closer');
    };

    return $this->warnings ? array_map($func, $this->warnings) : array();
  }

  function clean($html) {
    $this->output = $this->input = $html;
    HyperSafeEncodingError::checkUTF8($html);

    $this->prepare();
    $this->process();
    $this->finish();

    return $this->output;
  }

  protected function prepare() {
    // Double-encode existing &lt; and &gt; or they will be treated as if they
    // were normal tags after htmlspecialchars() with $double_encode = false.
    // This is undone in finish().
    $output = preg_replace('/&([lg]t;)/u', '&amp;\1', $this->output);

    $flags = ENT_NOQUOTES | static::$html5Flag;
    static::$html5Flag and $flags |= ENT_SUBSTITUTE;
    $output = htmlspecialchars($output, $flags, 'utf-8', false);

    $replace = $this->keepComments ? '<!--\1-->' : '';
    $output = preg_replace('/&lt;!--(.*?)(--&gt;|$)/us', $replace, $output);

    $this->output = $output;
  }

  protected function finish() {
    $this->output = preg_replace('/&amp;([lg]t;)/u', '&\1', $this->output);

    if (is_string($this->lineBreaks)) {
      $this->output = preg_replace("/\r?\n|\r/u", $this->lineBreaks, $this->output);
    }
  }

  protected function process() {
    $regexp = '&lt;(/?)([a-zA-Z0-9]+)(\s.*?)?(/?)&gt;()';
    $this->keepComments and $regexp .= '|<!--|-->';

    if (!preg_match_all("~$regexp~us", $this->output, $this->tokens,
                        PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      $this->tokens = array();
    }

    $this->stack = array();
    $this->shift = 0;
    $this->index = -1;
    $commenting = false;

    for ($index = &$this->index; $token = &$this->tokens[++$index]; ) {
      if ($isCmtStart = $token[0][0] === '<!--' or $token[0][0] === '-->') {
        if ($commenting ^ $isCmtStart) {
          $commenting = !$commenting;
        } else {
          // Stop immediately because something is wrong with the markup - double
          // start or unmatched end should not be possible as prepare() only
          // unescapes matching comment tags.
          return $this->warn('double <!-- comment <!-- start; processing stopped');
        }
      } elseif (!$commenting) {
        list(, $isClosing, $tag, $attributes, $xmlCloser) = $token;
        $tag = strtolower($tag[0]);
        $stackItem = array($tag, $token, $this->shift);

        if ($xmlCloser[0] and $isClosing[0]) {
          $this->singleClosingTag();
        } elseif ($xmlCloser[0] and !isset($this->tags[".$tag"])) {
          $this->xmlNotSingleTag();
        } elseif (isset($this->tags[".$tag"])) {
          if ($isClosing[0]) {
            $this->closingSingle();
          } else {
            $str = $this->cleanStackItem($stackItem, true);
            $str === null or $this->shift += $this->replace($stackItem, $str);
          }
        } elseif (!isset($this->tags[$tag])) {
          $this->badTag();
        } elseif (!$isClosing[0]) {
          array_unshift($this->stack, $stackItem);
        } elseif (ltrim($attributes[0]) !== '') {
          $this->closingWithAttributes();
        } elseif ($this->stack and $this->stack[0][0] === $tag) {
          $this->processMatching($this->stack[0], $stackItem);
          array_shift($this->stack);
        } else {  // bad nesting: <a><b></a></b>.
          $removed = array();
          while ($this->stack and $this->stack[0][0] !== $tag) {
            $removed[] = array_shift($this->stack);
          }

          if ($this->stack) {
            --$index;  // found matching opening tag - repeat the iteration.
            $this->badNesting($removed);
          } else {
            $this->stack = $removed;  // restore stack.
            $this->badNesting();
          }
        }
      }
    }
  }

  protected function processMatching(array $opener, array $closer) {
    $closerStr = $this->cleanStackItem($closer);
    if ($closerStr !== null) {
      $openerStr = $this->cleanStackItem($opener);
      if ($openerStr !== null) {
        // Replacing closer first as it goes before opener and won't shift its offset.
        $this->shift += $this->replace($closer, $closerStr) +
                        $this->replace($opener, $openerStr);
      }
    }
  }

  protected function cleanStackItem(array $stackItem, $isSingle = false) {
    list(, $isClosing, $tag, $attributes) = $stackItem[1];
    $tag = strtolower($tag[0]);

    $rules = $this->tags[ $isSingle ? ".$tag" : $tag ];
    $seen = array();

    while (is_string($rules)) {
      if (isset($seen[$tag])) {
        return $this->warn("recursive \$tags reference of $tag - tag discarded");
      } else {
        $seen[$tag] = true;
        $rules = $this->tags[$tag = $rules];
      }
    }

    if ($isClosing[0]) {
      return "</$tag>";
    } else {
      $attrs = $this->parseAttributes($rules, $attributes[0]);

      foreach ($rules as $rule) {
        if ($rule[0] === '!') {
          $rule = $this->parseRule($rule);
          if (!isset($attrs[ $rule['name'] ])) {
            return $this->missingRequired($rule);
          }
        }
      }

      foreach ($attrs as $attr => $value) { $tag .= " $attr=$value"; }
      return "<$tag>";
    }
  }

  // $str = 'foo="bar&quot;" baz=off'.
  // Returns array('attr' => '"encoded &quot;value"').
  protected function parseAttributes(array $rules, $str) {
    $result = array();

    foreach ($this->parseMap($str, ' ', '=') as $attr => $value) {
      $attr = strtolower($attr);
      $rule = $this->findRule($rules, $attr) ?: $this->findRule($this->tags[''], $attr);

      if (!$rule) {
        $this->badAttribute($attr, $value);
      } else {
        $value === null and $value = $attr;   // <a foo>
        $value === '' and $value = '""';      // <a foo=>
        $quote = $value[0];
        $decoded = $encoded = null;

        if ($quote !== '"' and $quote !== "'") {
          // <a foo=bar>
          $decoded = $value;
        } else {
          // Removing trailing stuff as in <a foo="bar"tail attr2=...>.
          $encoded = $quote.preg_replace("/$quote.*$/us", '', substr($value, 1)).$quote;
          if (strlen($encoded) !== strlen($value)) {
            $this->warn("attribute value has a tail: \"$value\" - tail discarded");
          }
        }

        // Here either $decoded or $encoded (wrapped in quotes) is set.

        if ($checker = $rule['checker']) {
          isset($decoded) or $decoded = $this->decodeAttribute(substr($encoded, 1, -1));
          $value = $this->check($checker, $decoded);

          if ($value === null) {
            $this->badAttributeValue($attr, $decoded);
          } else {
            $value = '"'.$this->encodeAttribute($value).'"';
          }
        } else {
          // Optimization - if no checker needs to be ran then we don't have to
          // decode and encode the value again. As a side effect this may produce
          // a='single quoted' attribute if it was in the input but it's still valid.
          $value = isset($encoded) ? $encoded : '"'.$this->encodeAttribute($decoded).'"';
        }

        $value === null or $result[$attr] = $value;
      }
    }

    return $result;
  }

  // $itemSepar and $keySepar must be strings 1 symbol long.
  // Returns array('attr' => 'value') where 'attr' is always non-empty string
  // consisting only of: a-z A-Z 0-9 _ -
  protected function parseMap($str, $itemSepar, $keySepar) {
    // Neither HTML nor CSS use \escaping which is very convenient as we can
    // just cut strings for all allowed characters except the " or ' itself.
    $regexp = '/"[^"]*("|$)|\'[^\']*(\'|$)/u';
    $flat = preg_replace_callback($regexp, function ($match) use (&$unclosed) {
      empty($match[1]) and empty($match[2]) and $unclosed = true;   // unterminated string.
      return str_repeat('_', strlen($match[0]));
    }, $str);

    // $str  = 'foo="bar&quot;" baz=off'.
    // $flat = 'foo=___________ bar=off'.

    $map = array();
    $pos = 0;

    if (!empty($unclosed)) {
      $this->unterminatedMapString($str);
    } else {
      foreach (explode($itemSepar, $flat) as $item) {
        $key = explode($keySepar, $item, 2);
        $cleanKey = trim($key[0]);

        if ($cleanKey === '') {
          // Skip:  =foo  (has separator but empty key) or just consecutive $itemSepar.
        } elseif (ltrim($cleanKey, 'a..zA..Z0..9_-') !== '') {
          $this->badMapKey($cleanKey);
        } elseif (isset($key[1])) {
          $map[$cleanKey] = trim(substr($str, $pos + strlen($key[0]) + 1 /* strlen($keySepar) */,
                                        strlen($item) - strlen($key[0]) - 1));
        } else {
          // foo  (no separator).
          $map[$cleanKey] = null;
        }

        $pos += strlen($item) + 1 /* strlen($itemSepar) */;
      }
    }

    return $map;
  }

  // $rules = array('[!]attr[ checker]', ...) - from $this->tags or $styles.
  protected function findRule(array $rules, $attr) {
    foreach ($rules as $rule) {
      $parsed = $this->parseRule($rule);
      $name = $parsed['name'];

      if ( strpbrk($name, '*?') ? $this->match($name, $attr) : $name === $attr ) {
        return $parsed;
      }
    }
  }

  protected function parseRule($rule) {
    return array(
      'name'      => ltrim(strtok($rule, ' '), '!'),
      'checker'   => trim(strtok(null)),
      'required'  => $rule[0] === '!',
    );
  }

  protected function check($checker, $value) {
    $funcs = &$this->checks[$checker];
    if (!isset($funcs)) {
      return $this->warn("undefined \$checks checker \"$checker\" - attribute discarded");
    }

    is_array($funcs) or $funcs = array($funcs);

    foreach ($funcs as $func) {
      if (is_string($func)) {
        $func[0] === '~' or $func = "~^$func~";
        $func .= 'u';

        if (!preg_match($func, $value)) {
          $value = $this->checkFailure($func, $value);
        }
      } else {
        $result = call_user_func($func, $value, $this);
        $result === true or $value = $result === false ? null : $result;
      }

      if ($value === null) { break; }
    }

    return $value;
  }

  protected function replace(array $stackItem, $str) {
    // $stackItem[1][0] = array('&lt;full&gt;', pos).
    // $stackItem[2] = shift.
    $pos = $stackItem[1][0][1] + $stackItem[2];
    $origLen = strlen($stackItem[1][0][0]);

    $this->output = substr($this->output, 0, $pos).
                    $str.
                    substr($this->output, $pos + $origLen);
    return strlen($str) - $origLen;
  }

  // $str = 'background-image: url("foo"); clear: both'.
  // Returns cleaned 'prop: value; ...'.
  function cleanCSS($str) {
    $result = '';

    foreach ($this->parseMap($str, ';', ':') as $prop => $value) {
      // It's null if item lacks ':', e.g.  a: b; this; d: e
      // ...and '' if there's nothing after ':'  a: b; this: ; d: e
      if ($value !== null and $value !== '') {
        $prop = strtolower($prop);
        $rule = $this->findRule($this->styles, $prop);

        if (!$rule) {
          $value = $this->badStyleProp($prop, $value);
        } elseif ($checker = $rule['checker']) {
          $value = $this->check($checker, $orig = $value);
          $value === null and $this->badStyle($prop, $orig);
        }

        $value === null or $result .= "; $prop: $value";
      }
    }

    return substr($result, 2);
  }

  /***
    Callbacks that subclasses may wish to override.
   ***/

  // Called if found a strange </tag/> construct.
  protected function singleClosingTag() {
    $this->warn('bad </markup/> - tag discarded');
  }

  // Called when encountered a XML-style single <tag /> that was not listed
  // as '.tag' in $this->tags, or not listed at all.
  protected function xmlNotSingleTag() {
    $this->warn('disallowed XML-style <tag /> - tag discarded');
  }

  // Called if input has </img> or similar. Does nothing (leaves escaped).
  protected function closingSingle() {
    $this->warn('closing single tag - tag discarded');
  }

  // Called when stumbling upon a tag not listed in $tags (left escaped).
  protected function badTag() {
    $this->warn('disallowed tag - tag discarded');
  }

  // Called when input contains </tag attr...>. Does nothing (leaves escaped tag).
  // Doesn't alter the stack so opening <tag> (if any) is still there.
  protected function closingWithAttributes() {
    $this->warn('closing tag has attribute(s) - tag discarded');
  }

  // Called when input has <a><b></a></b> or similar. If $removed is given
  // means that the prematurely closing tag actually had an opening somewhere
  // before. If $removed is null then closing tag wasn't opened and in this
  // case it was just ignored (stack unchanged, token kept escaped): <a></b></a>.
  protected function badNesting(array $removed = null) {
    if ($removed) {
      $tags = '';
      foreach ($removed as $stackItem) { $tags .= "<$stackItem[0]>"; }
      $this->warn("bad nesting (premature closing tag) - discarded opened $tags");
    } else {
      $this->warn('bad nesting (no opening tag) - tag discarded');
    }
  }

  // Called if a tag has missing one of mandatory !attributes.
  // $rule = result of calling parseRule().
  protected function missingRequired(array $rule) {
    $this->warn("missing required attribute \"$rule[name]\" - tag discarded");
  }

  // Called if an attribute value or CSS style didn't pass a check(s) defined
  // in $this->checks. Not called for callback checks.
  protected function checkFailure($regexp, $value) {
    $this->warn("value mismatching $regexp: \"$value\" - attribute discarded");
  }

  // Called when an attribute or CSS style has unmatching " or ':  foo: "bar
  protected function unterminatedMapString($str) {
    $this->warn("token contains unterminated string: \"$str\" - attributes discarded");
  }

  // Called when found an attribute name or CSS property containing wrong
  // symbols like  foo%bar="value"  or  foo!bar: value.
  protected function badMapKey($key) {
    $this->warn("bad attribute key name \"$key\" - attribute discarded");
  }

  // Called when found an attribute not listed in $this->tags.
  function badAttribute($attr, $value) {
    $this->warn("disallowed attribute \"$attr\": \"$value\" - attribute discarded");
  }

  // Called when a value for an attribute listed in $this->tags had to be ignored
  // because it didn't pass some checks.
  protected function badAttributeValue($attr, $value) {
    $this->warn("bad value of \"$attr\" attribute: \"$value\" - attribute discarded");
  }

  // Called when found an inlined CSS property not listed in $styles.
  function badStyleProp($prop, $value) {
    $this->warn("disallowed style property \"$prop\": \"$value\" - property discarded");
  }

  // Called when an inlined style has to be ignored because it didn't pass
  // some checks.
  protected function badStyle($prop, $value) {
    $this->warn("bad style of \"$prop\": \"$value\" - property discarded");
  }
}

HyperSafe::$html5Flag = defined('ENT_HTML5') ? ENT_HTML5 : 0;