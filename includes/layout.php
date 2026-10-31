<?php
/*
 * File: includes/layout.php
 * Purpose: Shared page chrome — one <html>/<head>/sidebar/session-bar for
 *          every Admin/Owner/Cashier screen. Pages should not write their
 *          own <html>, <head>, sidebar, or .userAdmin wrapper anymore;
 *          include includes/header.php / includes/footer.php instead
 *          (those two files just call the functions below).
 */
// Shared page chrome
function app_asset_prefix($context) {
    return $context === 'root' ? '' : '../';
}

function initials_from_name($name) {
    $name = trim((string)$name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/[\s._-]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 2));
    }
    return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
}

// One browser tab per login: only the first tab to open an app page may use it (see gate_tab_claim()).
// The receipt page is exempt because the POS "Print receipt" button deliberately opens it in a second tab.
function layout_tab_guard_enabled() {
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'Receipts.php';
}

// The cashier POS screen must stay on all day, so it is never signed out for inactivity.
function layout_inactivity_exempt() {
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'POS.php';
}

function render_app_open(array $opts) {
    $context = $opts['context'] ?? 'admin';
    $active = $opts['active'] ?? '';
    $roleTitle = $opts['role_title'] ?? null;
    $title = $opts['title'] ?? app_name();
    $bodyClass = trim((string)($opts['body_class'] ?? ''));
    $contentClass = $opts['content_class'] ?? 'userAdmin';
    $extraHead = $opts['extra_head'] ?? '';
    $breadcrumb = $opts['breadcrumb'] ?? [];
    // 'bare' => true renders no sidebar and no session bar (full-screen terminals such as the POS).
    $bare = !empty($opts['bare']);
    $root = app_asset_prefix($context);
    $styleVersion = filemtime(__DIR__ . '/../style.css');
    $brand = htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8');
    $pageTitle = htmlspecialchars($title . ' · ' . app_name(), ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html>' . "\n";
    echo '<html lang="en">' . "\n";
    echo '<head>' . "\n";
    echo '<meta charset="UTF-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
    echo '<title>' . $pageTitle . '</title>' . "\n";
    echo '<link rel="icon" type="image/png" sizes="32x32" href="' . htmlspecialchars($root . 'assets/favicon-32.png', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<link rel="icon" type="image/png" sizes="16x16" href="' . htmlspecialchars($root . 'assets/favicon-16.png', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . htmlspecialchars($root . 'assets/apple-touch-icon.png', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">' . "\n";
    echo '<link rel="stylesheet" href="' . htmlspecialchars($root . 'style.css?v=' . $styleVersion, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo $extraHead;
    if (layout_tab_guard_enabled() && gate_limits_enabled()) {
        // Keep the page hidden until the tab lock has been checked (revealed by the script in render_app_close()).
        echo '<style id="tab-guard-hide">body{visibility:hidden}</style><noscript><style>body{visibility:visible}</style></noscript>' . "\n";
    }
    if ($context === 'admin') {
        $adminStyleVersion = filemtime(__DIR__ . '/../Admin/admin.css');
        echo '<link rel="stylesheet" href="' . htmlspecialchars($root . 'Admin/admin.css?v=' . $adminStyleVersion, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    echo '</head>' . "\n";
    if (!$bare) {
        $bodyClass = trim($bodyClass . ' app-shell');
        if ($context === 'admin') {
            $bodyClass = trim($bodyClass . ' admin-context');
        } elseif ($context === 'owner') {
            $bodyClass = trim($bodyClass . ' owner-context');
        }
    }
    echo '<body' . ($bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"' : '') . '>' . "\n";
    if (!$bare) {
        render_sidebar($context, $active, $roleTitle);
    }
    echo '<div class="' . htmlspecialchars($contentClass, ENT_QUOTES, 'UTF-8') . '">' . "\n";

    if (!$bare) {
        echo render_breadcrumb_html($breadcrumb);
    }

    // Toast area — replaces alert(). Renders anything queued via
    // flash_success()/flash_error()/flash_warning() (see includes/auth.php),
    // including messages queued right before a redirect.
    echo '<div id="app-toast-stack" class="app-toast-stack" aria-live="polite"></div>' . "\n";
    if (function_exists('flash_pull')) {
        $toasts = flash_pull();
        if (!empty($toasts)) {
            echo '<script>window.__appToasts = ' . json_encode($toasts, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>' . "\n";
        }
    }
}

// Breadcrumb and page-heading rendering
function render_breadcrumb_html($breadcrumb) {
    $breadcrumb = array_values(array_filter((array)$breadcrumb, function ($c) {
        return trim((string)$c) !== '';
    }));
    if (empty($breadcrumb)) {
        return '';
    }
    $out = '<nav class="app-breadcrumb" aria-label="Breadcrumb">';
    $last = count($breadcrumb) - 1;
    foreach ($breadcrumb as $i => $crumb) {
        if ($i > 0) {
            $out .= '<span class="crumb-sep">·</span>';
        }
        if ($i === $last) {
            $out .= '<span class="crumb-current">' . htmlspecialchars($crumb, ENT_QUOTES, 'UTF-8') . '</span>';
        } else {
            $out .= '<span>' . htmlspecialchars($crumb, ENT_QUOTES, 'UTF-8') . '</span>';
        }
    }
    $out .= '</nav>';
    return $out;
}

// Optional helper for pages that want a page-title/subtitle block styled
// consistently with the rest of the app (used in place of a hand-rolled
// `.page-header` div). Purely optional — pages can still render their own.
function render_page_heading($title, $subtitle = '') {
    echo '<div class="app-page-heading">';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    if ($subtitle !== '') {
        echo '<p>' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '</div>';
}

function render_app_close(array $opts = []) {
    $context = $opts['context'] ?? 'admin';
    $root = app_asset_prefix($context);
    $extraJs = $opts['extra_js'] ?? '';
    $scriptVersion = filemtime(__DIR__ . '/../script.js');
    echo '</div>' . "\n";
    echo $extraJs;
    // Keeps this device's slot alive while the page is open, and returns to login if the slot was lost.
    $pingUrl = $root . 'api/session_ping.php';
    $endedUrl = $root . 'Login.php?reason=ended';
    if (!layout_tab_guard_enabled()) {
        echo '<script>(function(){var u=' . json_encode($pingUrl, JSON_UNESCAPED_SLASHES) . ',l=' . json_encode($endedUrl, JSON_UNESCAPED_SLASHES)
            . ';function p(){fetch(u,{credentials:"same-origin",cache:"no-store",headers:{"Accept":"application/json"}}).then(function(r){if(r.status===401){location.href=l;}}).catch(function(){});}'
            . 'setInterval(p,60000);document.addEventListener("visibilitychange",function(){if(!document.hidden){p();}});})();</script>' . "\n";
    } else {
        $roleLabel = auth_user_role() ?: 'This account';
        $logoutUrl = $root . 'logout.php?reason=timeout';
        $inactMs = gate_inactivity_minutes() * 60 * 1000;
        $exempt = layout_inactivity_exempt() ? 'true' : 'false';
        echo '<script>(function(){var U=' . json_encode($pingUrl, JSON_UNESCAPED_SLASHES)
            . ',L=' . json_encode($endedUrl, JSON_UNESCAPED_SLASHES)
            . ',ROLE=' . json_encode($roleLabel, JSON_HEX_TAG | JSON_HEX_AMP) . ',LOGIN=' . json_encode($root . 'Login.php?stay=1', JSON_UNESCAPED_SLASHES)
            . ',OUT=' . json_encode($logoutUrl, JSON_UNESCAPED_SLASHES) . ',LIMIT=' . (int)$inactMs . ',EXEMPT=' . $exempt . ';' . "\n"
            . <<<'JS'
function rnd(){var a=new Uint8Array(16),s='',i;(window.crypto||window.msCrypto).getRandomValues(a);for(i=0;i<16;i++){s+=('0'+a[i].toString(16)).slice(-2);}return s;}
var tab='',page=rnd(),claimed=false,blocked=false,alive=true,timer=null,bc=null;
try{tab=sessionStorage.getItem('agrivet_tab')||'';}catch(e){}
if(!/^[a-f0-9]{32}$/.test(tab)){tab=rnd();try{sessionStorage.setItem('agrivet_tab',tab);}catch(e){}}
function reveal(){var s=document.getElementById('tab-guard-hide');if(s&&s.parentNode){s.parentNode.removeChild(s);}}
function q(){return '?tab='+tab+'&page='+page;}
var act=false,lastAct=Date.now(),lastSent=Date.now(),warn=null;
function check(){var a=act||EXEMPT;act=false;if(a){lastSent=Date.now();}return fetch(U+q()+(a?'&act=1':''),{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});}
function block(){
  blocked=true;clearInterval(timer);reveal();
  document.title=ROLE+' is open in another tab';
  document.body.className='';
  document.body.innerHTML='<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:#f4f6f4;font-family:DM Sans,Arial,sans-serif;">'
   +'<div style="max-width:440px;background:#fff;border:1px solid #d9dfd9;border-radius:14px;padding:32px;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.08);">'
   +'<h1 style="margin:0 0 10px;font-size:22px;color:#1b2b1b;">Already open in another tab</h1>'
   +'<p style="margin:0 0 22px;color:#4a5a4a;line-height:1.5;">The <strong id="tg-role"></strong> account is already open in another tab or window of this browser. Only one tab per role is allowed. Switch to that tab, or close it and try again here.</p>'
   +'<button id="tg-retry" type="button" style="padding:10px 22px;border:0;border-radius:8px;background:#2e7d32;color:#fff;font-size:15px;font-weight:600;cursor:pointer;">Try again</button> '
   +'<a id="tg-login" style="display:inline-block;padding:10px 22px;border:1px solid #2e7d32;border-radius:8px;color:#2e7d32;font-size:15px;font-weight:600;text-decoration:none;">Back to login page</a>'
   +'<p id="tg-msg" style="margin:14px 0 0;color:#b00020;min-height:1.2em;font-size:14px;"></p></div></div>';
  document.getElementById('tg-role').textContent=ROLE;
  document.getElementById('tg-login').href=LOGIN;
  document.getElementById('tg-retry').onclick=function(){
    check().then(function(r){
      if(r.status===401){location.href=L;}
      else if(r.status===409){document.getElementById('tg-msg').textContent='Still open in another tab.';}
      else{location.reload();}
    }).catch(function(){document.getElementById('tg-msg').textContent='Could not reach the server. Try again.';});
  };
}
// Shift auto-close (cashier POS): warn before the closing time, and tell the cashier when it happened.
var shiftShown=false,shiftBar=null;
function shiftTick(d){
  var id=window.agrivetShiftId;
  if(!d||!id||shiftShown){return;}
  if(d.shift_auto_closed||(typeof d.open_shift_id!=='undefined'&&d.open_shift_id!==id)){
    shiftShown=true;
    var o=document.createElement('div');
    o.style.cssText='position:fixed;inset:0;z-index:100000;background:rgba(15,30,15,.6);display:flex;align-items:center;justify-content:center;padding:20px;font-family:DM Sans,Arial,sans-serif;';
    o.innerHTML='<div style="max-width:440px;background:#fff;border-radius:14px;padding:30px;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,.3);">'
      +'<h2 style="margin:0 0 10px;font-size:21px;color:#1b2b1b;">Your shift was closed automatically</h2>'
      +'<p id="sh-msg" style="margin:0 0 20px;color:#4a5a4a;line-height:1.5;"></p>'
      +'<button id="sh-ok" type="button" style="padding:10px 24px;border:0;border-radius:8px;background:#2e7d32;color:#fff;font-size:15px;font-weight:600;cursor:pointer;">OK</button></div>';
    document.body.appendChild(o);
    document.getElementById('sh-msg').textContent='The shift was still open at '+(d.shift_cutoff_label||'closing time')+', so the system closed it. The drawer cash was not counted, so please tell your manager. You can start a new shift when you are ready.';
    document.getElementById('sh-ok').onclick=function(){location.reload();};
    return;
  }
  if(d.shift_cutoff_in!==null&&typeof d.shift_cutoff_in!=='undefined'&&d.shift_cutoff_in<=900){
    if(!shiftBar){shiftBar=document.createElement('div');shiftBar.style.cssText='position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:99998;background:#7a2e0e;color:#fff;padding:10px 18px;border-radius:10px;font:600 14px DM Sans,Arial,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.3);max-width:92vw;text-align:center;';document.body.appendChild(shiftBar);}
    var m=Math.max(1,Math.ceil(d.shift_cutoff_in/60));
    shiftBar.textContent='Your shift closes automatically at '+d.shift_cutoff_label+' (in '+m+' min). End your shift now to count the drawer.';
    shiftBar.style.display='block';
  }else if(shiftBar){shiftBar.style.display='none';}
}
function ping(){
  if(blocked){return;}
  check().then(function(r){
    if(r.status===401){location.href=L;return;}
    if(r.status===409){block();return;}
    claimed=true;reveal();
    return r.json().then(shiftTick).catch(function(){});
  }).catch(function(){reveal();});
}
// Auto sign-out after LIMIT ms without real activity (mouse, keys, touch, scroll). Warn 1 minute before.
function onAct(){
  act=true;lastAct=Date.now();
  if(warn&&warn.style.display!=='none'){warn.style.display='none';}
  if(!blocked&&Date.now()-lastSent>120000){ping();}
}
['mousemove','mousedown','keydown','touchstart','wheel','scroll','click'].forEach(function(ev){window.addEventListener(ev,onAct,{passive:true,capture:true});});
setInterval(function(){
  if(blocked||EXEMPT){return;}
  var idle=Date.now()-lastAct;
  if(idle>=LIMIT){location.href=OUT;return;}
  if(idle>=LIMIT-60000){
    if(!warn){warn=document.createElement('div');warn.style.cssText='position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;background:#7a2e0e;color:#fff;padding:12px 20px;border-radius:10px;font:600 14px DM Sans,Arial,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.3);';warn.textContent='No activity detected. You will be signed out in 1 minute. Move the mouse or press a key to stay signed in.';document.body.appendChild(warn);}
    warn.style.display='block';
  }
},15000);
// A duplicated tab copies sessionStorage, so it would share this tab's id. If a live page already uses
// the same id, the newcomer takes a fresh one (and is then refused by the server).
try{
  bc=new BroadcastChannel('agrivet_tab_guard');
  bc.onmessage=function(e){
    var m=e.data||{};
    if(m.t==='hello'&&m.tab===tab&&m.page!==page&&claimed&&!blocked&&alive){bc.postMessage({t:'dup',tab:tab,page:m.page});}
    else if(m.t==='dup'&&m.page===page&&!claimed){tab=rnd();try{sessionStorage.setItem('agrivet_tab',tab);}catch(x){}}
  };
  bc.postMessage({t:'hello',tab:tab,page:page});
}catch(e){bc=null;}
setTimeout(ping,bc?200:0);
setTimeout(reveal,4000);
timer=setInterval(ping,20000);
document.addEventListener('visibilitychange',function(){if(!document.hidden){ping();}});
window.addEventListener('pageshow',function(e){alive=true;if(e.persisted){ping();}});
window.addEventListener('pagehide',function(){
  alive=false;
  if(claimed&&!blocked&&navigator.sendBeacon){
    try{navigator.sendBeacon(U,new URLSearchParams({tab:tab,page:page,release:'1'}));}catch(e){}
  }
});
})();</script>
JS
            . "\n";
    }
    // Live refresh: any element with data-live="seconds" and an id is re-fetched in the background and swapped in.
    echo <<<'LIVEJS'
<script>(function(){
var regs=document.querySelectorAll('[data-live]');if(!regs.length){return;}
var sec=Math.max(10,parseInt(regs[0].getAttribute('data-live'),10)||30),busy=false;
var badge=document.createElement('div');
badge.style.cssText='position:fixed;right:14px;bottom:14px;z-index:9000;background:#fff;border:1px solid #cfd8cf;border-radius:999px;padding:5px 12px;font:600 12px DM Sans,Arial,sans-serif;color:#2e5d34;box-shadow:0 2px 8px rgba(0,0,0,.12);display:flex;align-items:center;gap:6px;';
badge.innerHTML='<span style="width:8px;height:8px;border-radius:50%;background:#2e9e3f;display:inline-block"></span><span id="live-time"></span>';
document.body.appendChild(badge);
function stamp(){document.getElementById('live-time').textContent='Live \u00b7 '+new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',second:'2-digit'});}
stamp();
function typing(){var a=document.activeElement;return a&&/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName);}
function refresh(){
  if(document.hidden||busy||typing()){return;}
  busy=true;
  fetch(location.href,{credentials:'same-origin',cache:'no-store',headers:{'X-Live-Refresh':'1'}})
    .then(function(r){if(r.redirected||!r.ok){location.reload();return null;}return r.text();})
    .then(function(t){
      if(!t){return;}
      var d=new DOMParser().parseFromString(t,'text/html');
      document.querySelectorAll('[data-live]').forEach(function(el){
        if(!el.id){return;}var n=d.getElementById(el.id);if(n){el.innerHTML=n.innerHTML;}
      });
      stamp();
    }).catch(function(){}).then(function(){busy=false;});
}
setInterval(refresh,sec*1000);
document.addEventListener('visibilitychange',function(){if(!document.hidden){refresh();}});
})();</script>

LIVEJS;
    echo '<script src="' . htmlspecialchars($root . 'script.js?v=' . $scriptVersion, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    echo '</body></html>' . "\n";
}