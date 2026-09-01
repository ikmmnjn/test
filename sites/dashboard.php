<?php
declare(strict_types=1);
session_start();

/**
 * 전체 기관 검사 결과를 한 페이지에서 확인하는 대시보드.
 * sites/ 에 1개만 생성되며(기관별로 복제되지 않음), submissions_* 테이블을
 * DB에서 자동으로 찾아 목록에 보여줍니다 — 기관이 늘어나도 이 파일은 그대로 둬도 됩니다.
 *
 * 비밀번호는 db.config.php 의 dashboard_password 값입니다. 이름·전화번호·점수 같은
 * 개인정보가 그대로 보이는 페이지이니 반드시 HTTPS 도메인에서만 접속하세요.
 */

$configPath = __DIR__ . '/db.config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    die('DB config missing: ' . $configPath);
}
$config = require $configPath;

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function label_for_table(string $table): string
{
    $name = preg_replace('/^submissions_/', '', $table);
    if (substr($name, -5) === '_post') {
        return substr($name, 0, -5) . ' (사후)';
    }
    if (substr($name, -4) === '_pre') {
        return substr($name, 0, -4) . ' (사전)';
    }
    // 구버전(접미사 없음)으로 만들어진 테이블과의 호환용
    return $name . ' (사전)';
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: dashboard.php');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $expected = (string) ($config['dashboard_password'] ?? '');
    if ($expected !== '' && $expected !== '__FILL_IN_ON_SERVER__' && hash_equals($expected, (string) $_POST['password'])) {
        $_SESSION['dash_auth'] = true;
    } else {
        $loginError = '비밀번호가 올바르지 않습니다.';
    }
}

if (empty($_SESSION['dash_auth'])) {
    ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>검사 결과 대시보드 로그인</title>
<style>
  body { font-family: 'Malgun Gothic', sans-serif; max-width: 360px; margin: 120px auto; padding: 0 20px; color: #2E2F31; }
  h2 { font-size: 1.1rem; }
  input { width: 100%; padding: 10px; margin: 8px 0; box-sizing: border-box; border: 1px solid #DEDFE1; border-radius: 6px; font-size: 16px; }
  button { width: 100%; padding: 10px; background: #14B8A6; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 0.95rem; font-weight: 700; }
  .err { color: #A32D2D; font-size: 0.82rem; }
</style>
</head>
<body>
  <h2>검사 결과 대시보드</h2>
  <?php if ($loginError !== ''): ?><p class="err"><?= e($loginError) ?></p><?php endif; ?>
  <form method="post">
    <input type="password" name="password" placeholder="비밀번호" autofocus>
    <button type="submit">로그인</button>
  </form>
</body>
</html>
    <?php
    exit;
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $config['dbname'], $config['charset'] ?? 'utf8mb4'),
        $config['user'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('DB connection failed');
}

// submissions_ 로 시작하는 테이블을 자동으로 찾음 — 기관 추가 시 이 파일을 손댈 필요 없음
$tables = [];
foreach ($pdo->query('SHOW TABLES') as $row) {
    $name = array_values($row)[0];
    if (strpos($name, 'submissions_') === 0) {
        $tables[] = $name;
    }
}
sort($tables);

// 사이드바에서 드래그로 바꾼 순서를 이 파일 옆에 저장해두고 그대로 사용합니다.
// 순서에 없는(새로 생긴) 테이블은 뒤에 이름순으로 붙습니다.
$orderPath = __DIR__ . '/dashboard-order.json';
if (is_file($orderPath)) {
    $savedOrder = json_decode(file_get_contents($orderPath), true);
    if (is_array($savedOrder)) {
        $ordered = array_values(array_intersect($savedOrder, $tables));
        $remaining = array_values(array_diff($tables, $ordered));
        sort($remaining);
        $tables = array_merge($ordered, $remaining);
    }
}

// 사이드바 테이블 순서 저장 — POST 전용, 저장 전 화이트리스트($tables)와 대조해 걸러냅니다.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_order'])) {
    $newOrder = json_decode((string) $_POST['save_order'], true);
    header('Content-Type: application/json; charset=utf-8');
    if (is_array($newOrder)) {
        $safeOrder = array_values(array_intersect($newOrder, $tables));
        file_put_contents($orderPath, json_encode($safeOrder, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid order']);
    }
    exit;
}

// 응답 1건 삭제 — POST 전용, 테이블은 항상 위 화이트리스트($tables)와 대조합니다.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_row_table'], $_POST['delete_row_id'])) {
    $delTable = (string) $_POST['delete_row_table'];
    $delId = (int) $_POST['delete_row_id'];
    if (in_array($delTable, $tables, true) && $delId > 0) {
        $stmt = $pdo->prepare("DELETE FROM `$delTable` WHERE id = :id");
        $stmt->bindValue(':id', $delId, PDO::PARAM_INT);
        $stmt->execute();
    }
    header('Location: ?table=' . urlencode($delTable));
    exit;
}

// 테이블 통째로 삭제 — POST 전용, 되돌릴 수 없으므로 테이블 이름을 정확히 입력해야만 실행됩니다.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['drop_table'], $_POST['confirm_table_name'])) {
    $dropTable = (string) $_POST['drop_table'];
    if (in_array($dropTable, $tables, true) && $_POST['confirm_table_name'] === $dropTable) {
        $pdo->exec("DROP TABLE `$dropTable`");
    }
    header('Location: dashboard.php');
    exit;
}

// 테이블 이름은 항상 위에서 DB로부터 직접 가져온 목록에 있는 것만 허용(화이트리스트) —
// $_GET 값을 SQL에 바로 쓰지 않기 위한 안전장치.
$requestedExport = $_GET['export'] ?? null;
if ($requestedExport !== null && in_array($requestedExport, $tables, true)) {
    $table = $requestedExport;
    $stmt = $pdo->query("SELECT * FROM `$table` ORDER BY created_at DESC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $table . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // 엑셀에서 한글 깨지지 않도록 BOM 추가
    $first = true;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($first) {
            fputcsv($out, array_keys($r));
            $first = false;
        }
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

$selected = $_GET['table'] ?? null;
if ($selected !== null && !in_array($selected, $tables, true)) {
    $selected = null;
}

$rows = [];
$columns = [];
if ($selected !== null) {
    $stmt = $pdo->query("SELECT * FROM `$selected` ORDER BY created_at DESC LIMIT 1000");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $columns = array_keys($rows[0]);
    }
}

$counts = [];
foreach ($tables as $t) {
    $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>검사 결과 대시보드</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Malgun Gothic', sans-serif; margin: 0; background: #F4F5F6; color: #2E2F31; }
  header { background: #fff; border-bottom: 1px solid #DEDFE1; padding: 1rem 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
  header h1 { font-size: 1.1rem; margin: 0; flex: 1; }
  header a { font-size: 0.8rem; color: #8A8C8E; text-decoration: none; }
  .nav-toggle-btn {
    width: 32px; height: 32px; border: 1.5px solid #DEDFE1; border-radius: 6px; background: #fff;
    cursor: pointer; font-size: 0.95rem; color: #58595B; flex-shrink: 0;
  }
  .nav-toggle-btn:hover { border-color: #14B8A6; color: #0F766E; }
  .wrap { display: flex; }
  nav {
    position: relative; width: 220px; background: #fff; border-right: 1px solid #DEDFE1;
    min-height: calc(100vh - 57px); padding: 1rem 0; flex-shrink: 0; overflow: hidden;
    transition: width 0.15s ease, padding 0.15s ease;
  }
  nav.resizing { transition: none; }
  .wrap.nav-collapsed nav { width: 0 !important; padding: 1rem 0; border-right: none; }
  .nav-resize-handle {
    position: absolute; top: 0; right: -3px; width: 6px; height: 100%; cursor: col-resize; z-index: 5;
  }
  .nav-resize-handle:hover, .nav-resize-handle.dragging { background: rgba(20, 184, 166, 0.25); }
  .wrap.nav-collapsed .nav-resize-handle { display: none; }
  #nav-list a {
    display: flex; align-items: center; gap: 6px; padding: 0.6rem 1.25rem; font-size: 0.85rem; color: #2E2F31;
    text-decoration: none; cursor: grab;
  }
  #nav-list a.active, #nav-list a:hover { background: #E9F7F5; color: #0F766E; font-weight: 700; }
  #nav-list a.dragging { opacity: 0.4; }
  #nav-list .drag-handle { color: #C2C8CB; font-size: 0.75rem; flex-shrink: 0; }
  #nav-list .label { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  #nav-list .count { color: #8A8C8E; font-size: 0.75rem; flex-shrink: 0; }
  main { flex: 1; padding: 1.5rem; overflow-x: auto; min-width: 0; }
  table { border-collapse: collapse; width: 100%; background: #fff; font-size: 0.8rem; }
  th, td { border: 1px solid #DEDFE1; padding: 6px 10px; text-align: left; white-space: nowrap; }
  th { background: #F5F6F7; position: sticky; top: 0; }
  .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; gap: 0.75rem; }
  .toolbar-actions { display: flex; gap: 8px; }
  .btn { font-size: 0.8rem; padding: 0.4rem 0.8rem; border: 1px solid #DEDFE1; border-radius: 6px; text-decoration: none; color: #2E2F31; background: #fff; cursor: pointer; font-family: inherit; }
  .btn-danger { border-color: #E9A5A5; color: #A32D2D; }
  .btn-danger:hover { background: #FFF0F0; }
  .btn-del {
    font-size: 0.72rem; padding: 3px 8px; border: 1px solid #E9A5A5; border-radius: 5px; background: #fff;
    color: #A32D2D; cursor: pointer; font-family: inherit; white-space: nowrap;
  }
  .btn-del:hover { background: #FFF0F0; }
  .row-del-form { margin: 0; }
  .drop-table-box {
    display: none; margin-bottom: 1rem; padding: 1rem 1.25rem; background: #FFF0F0; border: 1.5px solid #E9A5A5;
    border-radius: 10px;
  }
  .drop-table-box.open { display: block; }
  .drop-table-box p { font-size: 0.82rem; color: #7A2020; margin: 0 0 0.6rem; line-height: 1.6; }
  .drop-table-box form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .drop-table-box input[type="text"] {
    flex: 1; min-width: 160px; padding: 0.4rem 0.6rem; border: 1.5px solid #E9A5A5; border-radius: 6px; font-size: 0.8rem;
  }
  .empty { color: #8A8C8E; padding: 3rem; text-align: center; }
</style>
</head>
<body>
<header>
  <button type="button" class="nav-toggle-btn" id="nav-toggle" title="목록 열기/닫기">☰</button>
  <h1>검사 결과 대시보드</h1>
  <a href="?logout=1">로그아웃</a>
</header>
<div class="wrap" id="dash-wrap">
  <nav id="dash-nav">
    <div id="nav-list">
      <?php foreach ($tables as $t): ?>
        <a href="?table=<?= e($t) ?>" class="<?= $t === $selected ? 'active' : '' ?>" draggable="true" data-table="<?= e($t) ?>">
          <span class="drag-handle">⠿</span>
          <span class="label"><?= e(label_for_table($t)) ?></span>
          <span class="count"><?= $counts[$t] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if (!$tables): ?><p style="padding:0 1.25rem; font-size:0.8rem; color:#8A8C8E;">아직 저장된 데이터가 없습니다.</p><?php endif; ?>
    <div class="nav-resize-handle" id="nav-resize" title="드래그해서 너비 조절"></div>
  </nav>
  <main>
    <?php if ($selected === null): ?>
      <p class="empty">왼쪽에서 기관을 선택하세요.</p>
    <?php else: ?>
      <div class="toolbar">
        <strong><?= e(label_for_table($selected)) ?> · <?= count($rows) ?>건</strong>
        <div class="toolbar-actions">
          <a class="btn" href="?export=<?= e($selected) ?>">CSV 다운로드</a>
          <button type="button" class="btn btn-danger" onclick="document.getElementById('drop-table-box').classList.add('open')">테이블 삭제</button>
        </div>
      </div>
      <div class="drop-table-box" id="drop-table-box">
        <p><strong><?= e($selected) ?></strong> 테이블과 그 안의 응답 <?= count($rows) ?>건이 <strong>영구히</strong> 삭제됩니다.
          확인을 위해 테이블 이름을 정확히 입력하세요.</p>
        <form method="post" onsubmit="return confirm('정말 삭제할까요? 되돌릴 수 없습니다.');">
          <input type="hidden" name="drop_table" value="<?= e($selected) ?>">
          <input type="text" name="confirm_table_name" placeholder="<?= e($selected) ?>" autocomplete="off" required>
          <button type="submit" class="btn btn-danger">영구 삭제</button>
          <button type="button" class="btn" onclick="document.getElementById('drop-table-box').classList.remove('open')">취소</button>
        </form>
      </div>
      <?php if (!$rows): ?>
        <p class="empty">아직 저장된 응답이 없습니다.</p>
      <?php else: ?>
        <table>
          <thead><tr><?php foreach ($columns as $c): ?><th><?= e($c) ?></th><?php endforeach; ?><th></th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <?php foreach ($columns as $c): ?><td><?= e($r[$c]) ?></td><?php endforeach; ?>
                <td>
                  <form class="row-del-form" method="post" onsubmit="return confirm('이 응답을 삭제할까요? 되돌릴 수 없습니다.');">
                    <input type="hidden" name="delete_row_table" value="<?= e($selected) ?>">
                    <input type="hidden" name="delete_row_id" value="<?= e($r['id']) ?>">
                    <button type="submit" class="btn-del">삭제</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>
<script>
  // 사이드바 접기/펼치기 — 브라우저에 상태 기억
  var navToggle = document.getElementById('nav-toggle');
  var dashWrap = document.getElementById('dash-wrap');
  var dashNav = document.getElementById('dash-nav');
  var NAV_MIN_WIDTH = 160;
  var NAV_MAX_WIDTH = 420;
  var navWidth = parseInt(localStorage.getItem('dash_nav_width'), 10);
  if (!navWidth || isNaN(navWidth)) navWidth = 220;

  function applyNavCollapsed(collapsed) {
    dashWrap.classList.toggle('nav-collapsed', collapsed);
    // 접었다 폈다 해도 폭을 기억하도록, 접힐 땐 인라인 폭을 지워서 CSS의 width:0 규칙이
    // 적용되게 하고, 펼 땐 기억해둔 폭을 다시 인라인으로 넣어줍니다.
    dashNav.style.width = collapsed ? '' : navWidth + 'px';
  }
  applyNavCollapsed(localStorage.getItem('dash_nav_collapsed') === '1');
  navToggle.addEventListener('click', function () {
    var collapsed = !dashWrap.classList.contains('nav-collapsed');
    applyNavCollapsed(collapsed);
    localStorage.setItem('dash_nav_collapsed', collapsed ? '1' : '0');
  });

  // 사이드바 오른쪽 가장자리를 드래그해서 너비 조절
  var navResize = document.getElementById('nav-resize');
  var resizingNav = false;
  navResize.addEventListener('mousedown', function (e) {
    if (dashWrap.classList.contains('nav-collapsed')) return;
    resizingNav = true;
    navResize.classList.add('dragging');
    dashNav.classList.add('resizing');
    e.preventDefault();
  });
  window.addEventListener('mousemove', function (e) {
    if (!resizingNav) return;
    var rect = dashNav.getBoundingClientRect();
    var w = Math.max(NAV_MIN_WIDTH, Math.min(NAV_MAX_WIDTH, e.clientX - rect.left));
    navWidth = w;
    dashNav.style.width = w + 'px';
  });
  window.addEventListener('mouseup', function () {
    if (!resizingNav) return;
    resizingNav = false;
    navResize.classList.remove('dragging');
    dashNav.classList.remove('resizing');
    localStorage.setItem('dash_nav_width', String(navWidth));
  });

  // 사이드바 테이블 목록 — 드래그로 순서 변경, 뗄 때마다 서버에 저장
  var navList = document.getElementById('nav-list');
  var dragEl = null;
  if (navList) {
    Array.prototype.forEach.call(navList.querySelectorAll('a'), function (a) {
      a.addEventListener('dragstart', function () {
        dragEl = a;
        a.classList.add('dragging');
      });
      a.addEventListener('dragend', function () {
        a.classList.remove('dragging');
        dragEl = null;
        saveTableOrder();
      });
      a.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragEl || dragEl === a) return;
        var rect = a.getBoundingClientRect();
        var before = (e.clientY - rect.top) < rect.height / 2;
        navList.insertBefore(dragEl, before ? a : a.nextSibling);
      });
    });
  }
  function saveTableOrder() {
    var order = Array.prototype.map.call(navList.querySelectorAll('a'), function (a) {
      return a.dataset.table;
    });
    fetch('dashboard.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'save_order=' + encodeURIComponent(JSON.stringify(order))
    });
  }
</script>
</body>
</html>
