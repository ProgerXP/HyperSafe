<?php ob_start()?>

  </ol>
  <h2>Заголовок &mdash; Title</h2>
  <!-- Кодировка → UTF-8 -->

  <header data-any="data:title/%$#">
    <a href="data:text/html,&lt;img src=x onerror=alert(0)&gt;">
      Danger!
    </a>

    <img alt="Doesn't make sense without &quot;src&#x22;">
    <a href="#" target="a$#@z"><a href="#" target="_blank">Okay.</a></a>

    <p>
      Some <i><b>broken</i> nesting</b> &amp; keeping &lt;p&gt;↓
    </p>
  </header>

  <p>Co<u>mm<!&lt;/p&gt;-->en</u>t.</p>
  --
  <meta/>XML</meta>-<script/>way</script>

  <p><acronym title="foo!">is deprecated, now <abbr>rules</abbr></acronym>.</p>
  <p><acronym>Even if it's aliased &ndash; nesting must be <abbr>kept</acronym></abbr>!</p>

  <h1 style="background: url('javascript:foo;'); no: protection; ;">for IE 6</h1>

  <p><b>What? </img src="/"></b></p>
  <p>Proper single <wbr title=5> tag and nesting.</p> <wbr title=5 />
  <span title=>Empty</span> <ins title=''>title</ins> <s title="">.</s>
  <aside>Here <input data-foo=/> <input data-foo> too.</aside>

  <div style="margin: &quot; &#x22; &quot; &#34; ' &apos; &#x27; &#39;;border: up;smth:; font:"
       data-onmousemove="alert('Ouch &apos; + document.cookie + &#39;!')">
    Browser decodes entities inside attributes.
    <p style="margin: &quot;; igno: re; background: foo">Style discarded.</p>
    <p style="margin: &#x27;; igno: re; background: foo">Too.</p>
    <a style="margin: &#x27;; ">Tag discarded.</a>

    <!-- multi
      </div>
    line -->
  </div>

  <p>< b>ad<b>ad</ b>&middot;<i>ad< /i> <mark>&bull;</mark> <u/style>ad</u>•<s>ad</s data-x></p>

  <input data-custom readonly disabled=disabled placeholder=foo>

  <ul type=&quot; id='what's =on &quot;=smth going="here foo=">
    <li value='>Too <i>broken</i'></i>.</li>
    <li
        value="
        >
          <kbd data-foo="b&quot;a'r""" lang='baz"&amp;'''''>duh</kbd>
        </li
  >
    <li value="value='value=&quot;"
        style="icon: url('; cursor: url(&quot;;
               background: data:image/png,foo'); font: &quot;foo&#34;, bar">
      <strong>Unnest <wbr>me</wbr>!</strong>
    </li>
  </ul>

  <article>
    <DiV><P>N3STiNG.</p></dIv>
    <p>o_O →</p/>
    <iMG sRc="https://localhost" LoL> <em></Img>?</em>
    <LoL><b stYle="fOnt-SiZE: 18pX; oMG: w00t">ar</b></LoL>
  </article>

  <!--
    <b>Unterminated
    comment</b>
    <meta>

<?php
  $input = ob_get_clean();
  $customInput = isset($_REQUEST['input']);
  $customInput and $input = trim($_REQUEST['input']);
  $expected = $customInput ? $input : trim(file_get_contents(__DIR__.'/tested.html'));

  ini_set('display_errors', 'on');
  error_reporting(-1);

  require_once __DIR__.'/hypersafe.php';
  $hs = new HyperSafe;
  $hs->lineBreaks = $customInput ? null : "\n";
  $hs->keepComments = true;
  $ac = &$_REQUEST['autoclose'] and $hs->autoClose = $ac === '1' ? true : $ac;
  $output = trim($hs->clean($input));

  function esc($str) {
    return htmlspecialchars($str, ENT_NOQUOTES, 'utf-8');
  }

  function unescTags($str) {
    return strtr($str, array('&lt;' => '<', '&gt;' => '>'));
  }

  header('Content-Type: text/html; charset=utf-8');

  if ($expected !== $output) {
    echo '<h3 style="color: red">Output differs</h3>';
  }
?>

<div style="overflow: auto">
  <textarea style="width: 49%; float: left; height: 40em" readonly
    ><?php echo esc($expected)?></textarea>

  <textarea style="width: 49%; float: right; height: 40em" readonly
    ><?php echo esc($output)?></textarea>
</div>

<center>
  ↑ expected
  [ <a href="#" onclick="document.getElementById('_hsinput_').style.display = 'block'; return false">clean yours</a> ]
  produced ↑
</center>

<form action="" method="post" style="display: none" id="_hsinput_">
  <p>
    <textarea style="width: 100%; height: 35em" name="input"
              onfocus="this.select(); this.onfocus = null"
      ><?php echo esc($input)?></textarea>
  </p>
  <p style="text-align: center">
    <label><input type="radio" name="autoclose" value="" checked> don't autoclose tags</label>
    <label><input type="radio" name="autoclose" value="eof"> autoclose on EOF</label>
    <label><input type="radio" name="autoclose" value="1"> autoclose all tags</label>
  </p>
  <p style="text-align: center"><input type="submit"></p>
</form>

<h3>Warnings</h3>
<ol>
  <?php
    foreach ($hs->warnings() as $warning) {
      extract($warning);
      echo '<li>';
      echo '<b>', esc($msg), '</b> ';
      if ($opener) { echo "&lt;$opener[tagName] $opener[pos]&gt;"; }
      echo '&hellip;';
      if ($closer) { echo "&lt;$closer[tagName] $closer[pos]&gt;"; }
      if ($opener) { echo '<br><kbd>', esc(unescTags($opener['tag'])), '</kbd>'; }
      if ($closer) { echo '<br><kbd>', esc(unescTags($closer['tag'])), '</kbd>'; }
      echo '</li>';
    }
  ?>
</ol>

<div style="border: 1px solid silver; padding: 1em">
  <?php echo $output?>
</div>