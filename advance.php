<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================================
   ANRVHS STUDENT RECORDS MANAGEMENT SYSTEM
   Corrected version
   Database: anrvhs_database
========================================= */

/* DATABASE CONNECTION */
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'anrvhs_database';

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    $conn->set_charset('utf8mb4');
} catch (Throwable $ex) {
    exit('Database connection failed. Check XAMPP MySQL.');
}

/* HELPER FUNCTIONS */
function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function runStmt(mysqli $conn, string $sql, string $types = '', array $values = []): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $bind = [$types];
        foreach ($values as $key => $value) {
            $bind[] = &$values[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    return $stmt;
}

function pageUrl(array $change = []): string {
    $query = $_GET;
    unset($query['status'], $query['edit']);
    foreach ($change as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    return '?' . http_build_query($query);
}

function reportPageUrl(int $page): string {
    $query = $_GET;
    $query['report_page'] = $page;
    return '?' . http_build_query($query);
}

/* =========================================
   SIMPLE LOCALHOST PASSWORD GATE
   Password: 16092009
========================================= */
$systemPassword = '16092009';

if (isset($_POST['system_login'])) {
    if (hash_equals($systemPassword, (string)($_POST['system_password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['system_authenticated'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $loginError = 'Incorrect password. Please try again.';
}

if (isset($_GET['logout'])) {
    unset($_SESSION['system_authenticated']);
    session_regenerate_id(true);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (empty($_SESSION['system_authenticated'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ANRVHS | Login</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#eff6ff;font-family:Arial,sans-serif;color:#1e293b}
.login-card{width:min(92%,390px);background:#fff;padding:30px;border-radius:14px;box-shadow:0 12px 35px #0f172a20;text-align:center;border-top:5px solid #d6b84c}
.login-card img{width:85px;height:85px;object-fit:contain}.login-card h2{font-size:18px;color:#123b2a;margin:12px 0 5px}.login-card p{color:#64748b;font-size:13px}
input{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:7px;margin:12px 0}
button{width:100%;padding:12px;border:0;border-radius:7px;background:#2563eb;color:#fff;font-weight:bold;cursor:pointer}
.error{background:#fee2e2;color:#991b1b;padding:10px;border-radius:7px;font-size:13px}
</style>
</head>
<body>
<form class="login-card" method="POST">
<img src="assets/anrvhs-logo.png" alt="ANRVHS Logo">
<h2>ABULUG NATIONAL RURAL AND VOCATIONAL HIGH SCHOOL</h2>
<p>Student Records Management System</p>
<?php if (!empty($loginError)): ?><div class="error"><?php echo e($loginError); ?></div><?php endif; ?>
<input type="password" name="system_password" placeholder="Enter system password" required autofocus>
<button type="submit" name="system_login">Open Student Records</button>
</form>
</body>
</html>
<?php
exit;
endif;

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$error = '';
$success = '';

/* =========================================
   INDIVIDUAL STUDENT SEARCH ENDPOINT
   Must run before any HTML output.
========================================= */
if (isset($_GET['individual_search'])) {
    header('Content-Type: application/json; charset=utf-8');
    $searchIndividual = trim((string)$_GET['individual_search']);

    if (mb_strlen($searchIndividual) < 2) {
        echo json_encode([]);
        exit;
    }

    $like = '%' . $searchIndividual . '%';
    $stmt = runStmt(
        $conn,
        "SELECT st.student_id, st.lrn, st.fname, st.mname, st.lname,
                st.gender, st.guardian, sec.section_name, gl.grade_level
         FROM tblstudents st
         INNER JOIN tblsections sec ON sec.section_id = st.section_id
         INNER JOIN tblgradelevels gl ON gl.gradelevel_id = sec.gradelevel_id
         WHERE st.is_archived = 0
           AND (st.lrn LIKE ? OR st.fname LIKE ? OR COALESCE(st.mname,'') LIKE ?
                OR st.lname LIKE ?
                OR CONCAT(st.fname,' ',COALESCE(st.mname,''),' ',st.lname) LIKE ?)
         ORDER BY st.lname ASC, st.fname ASC
         LIMIT 20",
        'sssss',
        [$like, $like, $like, $like, $like]
    );
    $result = $stmt->get_result();
    $found = [];
    while ($row = $result->fetch_assoc()) $found[] = $row;
    $stmt->close();
    echo json_encode($found);
    exit;
}

/* =========================================
   POST ACTIONS: SAVE / ARCHIVE / RESTORE / DELETE
========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['system_login'])) {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Security token expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);

            if (in_array($action, ['archive', 'restore', 'delete'], true)) {
                if (!$id || $id < 1) throw new RuntimeException('Invalid student record.');

                if ($action === 'archive') {
                    $stmt = runStmt($conn, 'UPDATE tblstudents SET is_archived=1 WHERE student_id=? AND is_archived=0', 'i', [$id]);
                    if (!$stmt->affected_rows) throw new RuntimeException('Student record not found or already archived.');
                    $stmt->close();
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?status=archived');
                    exit;
                }

                if ($action === 'restore') {
                    $stmt = runStmt($conn, 'UPDATE tblstudents SET is_archived=0 WHERE student_id=? AND is_archived=1', 'i', [$id]);
                    if (!$stmt->affected_rows) throw new RuntimeException('Archived student record not found.');
                    $stmt->close();
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?view=archived&status=restored');
                    exit;
                }

                if ($action === 'delete') {
                    $stmt = runStmt($conn, 'DELETE FROM tblstudents WHERE student_id=? AND is_archived=1', 'i', [$id]);
                    if (!$stmt->affected_rows) throw new RuntimeException('Only archived student records can be permanently deleted.');
                    $stmt->close();
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?view=archived&status=deleted');
                    exit;
                }
            }

            if ($action === 'save') {
                $id = filter_var($_POST['student_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
                $lrn = trim($_POST['lrn'] ?? '');
                $fname = trim($_POST['fname'] ?? '');
                $mname = trim($_POST['mname'] ?? '');
                $lname = trim($_POST['lname'] ?? '');
                $gender = trim($_POST['gender'] ?? '');
                $guardian = trim($_POST['guardian'] ?? '');
                $sectionId = filter_var($_POST['section_id'] ?? null, FILTER_VALIDATE_INT);

                if ($lrn === '' || $fname === '' || $lname === '' || !$sectionId) {
                    throw new RuntimeException('LRN, first name, last name, and section are required.');
                }
                if (strlen($lrn)>20 || strlen($fname)>50 || strlen($mname)>50 || strlen($lname)>50 || strlen($guardian)>100) {
                    throw new RuntimeException('One or more fields exceed the allowed length.');
                }
                if (!in_array($gender, ['', 'Male', 'Female', 'Other'], true)) {
                    throw new RuntimeException('Please select a valid gender.');
                }

                $check = runStmt($conn, 'SELECT section_id FROM tblsections WHERE section_id=?', 'i', [$sectionId]);
                $validSection = $check->get_result()->num_rows === 1;
                $check->close();
                if (!$validSection) throw new RuntimeException('Selected section does not exist.');

                $mname = $mname === '' ? null : $mname;
                $gender = $gender === '' ? null : $gender;
                $guardian = $guardian === '' ? null : $guardian;

                if ($id > 0) {
                    $stmt = runStmt(
                        $conn,
                        'UPDATE tblstudents SET lrn=?,fname=?,mname=?,lname=?,gender=?,guardian=?,section_id=? WHERE student_id=? AND is_archived=0',
                        'ssssssii',
                        [$lrn,$fname,$mname,$lname,$gender,$guardian,$sectionId,$id]
                    );
                    $stmt->close();
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?status=updated');
                    exit;
                } else {
                    $stmt = runStmt(
                        $conn,
                        'INSERT INTO tblstudents (lrn,fname,mname,lname,gender,guardian,section_id,is_archived) VALUES (?,?,?,?,?,?,?,0)',
                        'ssssssi',
                        [$lrn,$fname,$mname,$lname,$gender,$guardian,$sectionId]
                    );
                    $stmt->close();
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?status=added');
                    exit;
                }
            }
        } catch (mysqli_sql_exception $ex) {
            $error = ((int)$ex->getCode() === 1062) ? 'That LRN already exists.' : 'A database error occurred. Check the information and try again.';
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
}

/* LOAD GRADES AND SECTIONS */
$gradeLevels = [];
$result = $conn->query('SELECT gradelevel_id,grade_level FROM tblgradelevels ORDER BY gradelevel_id');
while ($row = $result->fetch_assoc()) $gradeLevels[] = $row;

$sections = [];
$result = $conn->query(
    'SELECT s.section_id,s.section_name,s.gradelevel_id,g.grade_level
     FROM tblsections s INNER JOIN tblgradelevels g ON g.gradelevel_id=s.gradelevel_id
     ORDER BY g.gradelevel_id,s.section_name'
);
while ($row = $result->fetch_assoc()) $sections[] = $row;


/* ENROLLMENT STATISTICS: ACTIVE STUDENTS ONLY */
$gradeStats = [];

$gradeStatsQuery = "
    SELECT
        gl.gradelevel_id,
        gl.grade_level,
        COUNT(st.student_id) AS total_students,
        SUM(CASE
            WHEN LOWER(TRIM(COALESCE(st.gender,''))) = 'male'
            THEN 1 ELSE 0
        END) AS male_students,
        SUM(CASE
            WHEN LOWER(TRIM(COALESCE(st.gender,''))) = 'female'
            THEN 1 ELSE 0
        END) AS female_students
    FROM tblgradelevels gl
    LEFT JOIN tblsections sec
        ON sec.gradelevel_id = gl.gradelevel_id
    LEFT JOIN tblstudents st
        ON st.section_id = sec.section_id
        AND st.is_archived = 0
    GROUP BY gl.gradelevel_id, gl.grade_level
    ORDER BY gl.gradelevel_id ASC
";

$gradeStatsResult = $conn->query($gradeStatsQuery);

while ($row = $gradeStatsResult->fetch_assoc()) {
    $gradeStats[] = $row;
}


$sectionStats = [];

$sectionStatsQuery = "
    SELECT
        sec.section_id,
        sec.section_name,
        gl.grade_level,
        COUNT(st.student_id) AS total_students,
        SUM(CASE
            WHEN LOWER(TRIM(COALESCE(st.gender,''))) = 'male'
            THEN 1 ELSE 0
        END) AS male_students,
        SUM(CASE
            WHEN LOWER(TRIM(COALESCE(st.gender,''))) = 'female'
            THEN 1 ELSE 0
        END) AS female_students
    FROM tblsections sec
    INNER JOIN tblgradelevels gl
        ON gl.gradelevel_id = sec.gradelevel_id
    LEFT JOIN tblstudents st
        ON st.section_id = sec.section_id
        AND st.is_archived = 0
    GROUP BY
        sec.section_id,
        sec.section_name,
        gl.grade_level,
        gl.gradelevel_id
    ORDER BY gl.gradelevel_id ASC, sec.section_name ASC
";

$sectionStatsResult = $conn->query($sectionStatsQuery);

while ($row = $sectionStatsResult->fetch_assoc()) {
    $sectionStats[] = $row;
}

/* FILTERS AND PAGINATION */
$view = (($_GET['view'] ?? 'active') === 'archived') ? 'archived' : 'active';
$search = trim($_GET['search'] ?? '');
$gradeFilter = filter_var($_GET['grade'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$sectionFilter = filter_var($_GET['section'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$pageSize = (int)($_GET['per_page'] ?? 25);
if (!in_array($pageSize, [10,25,50,100], true)) $pageSize = 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$sortMap = [
    'lrn'=>'st.lrn', 'name'=>'st.lname', 'grade'=>'gl.gradelevel_id',
    'section'=>'sec.section_name', 'gender'=>'st.gender', 'guardian'=>'st.guardian'
];
$sort = $_GET['sort'] ?? 'name';
if (!isset($sortMap[$sort])) $sort = 'name';
$direction = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$orderBy = $sortMap[$sort] . ' ' . $direction . ',st.fname ASC';

$where = ['st.is_archived = ?'];
$types = 'i';
$params = [$view === 'archived' ? 1 : 0];

if ($search !== '') {
    $where[] = "(st.lrn LIKE ? OR st.fname LIKE ? OR COALESCE(st.mname,'') LIKE ?
        OR st.lname LIKE ? OR CONCAT(st.fname,' ',COALESCE(st.mname,''),' ',st.lname) LIKE ?
        OR COALESCE(st.guardian,'') LIKE ? OR sec.section_name LIKE ? OR gl.grade_level LIKE ?)";
    $like = '%' . $search . '%';
    for ($i=0; $i<8; $i++) { $types .= 's'; $params[] = $like; }
}
if ($gradeFilter > 0) { $where[]='gl.gradelevel_id=?'; $types.='i'; $params[]=$gradeFilter; }
if ($sectionFilter > 0) { $where[]='sec.section_id=?'; $types.='i'; $params[]=$sectionFilter; }

$whereSql = ' WHERE ' . implode(' AND ', $where);
$fromSql = ' FROM tblstudents st
    INNER JOIN tblsections sec ON sec.section_id=st.section_id
    INNER JOIN tblgradelevels gl ON gl.gradelevel_id=sec.gradelevel_id ';

$countStmt = runStmt($conn, 'SELECT COUNT(*) AS total '.$fromSql.$whereSql, $types, $params);
$totalRecords = (int)$countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRecords/$pageSize));
$page = min($page,$totalPages);
$offset = ($page-1)*$pageSize;

$dataSql = 'SELECT st.student_id,st.lrn,st.fname,st.mname,st.lname,st.gender,st.guardian,
                   st.section_id,sec.section_name,gl.gradelevel_id,gl.grade_level
            '.$fromSql.$whereSql.' ORDER BY '.$orderBy.' LIMIT ? OFFSET ?';
$dataStmt = runStmt($conn,$dataSql,$types.'ii',array_merge($params,[$pageSize,$offset]));
$students = $dataStmt->get_result();
$dataStmt->close();

/* EDIT RECORD */
$editStudent = null;
$editId = filter_var($_GET['edit'] ?? null,FILTER_VALIDATE_INT);
if ($editId && $editId>0 && $view==='active') {
    $stmt = runStmt($conn,'SELECT student_id,lrn,fname,mname,lname,gender,guardian,section_id FROM tblstudents WHERE student_id=? AND is_archived=0','i',[$editId]);
    $editStudent = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$showModal = $editStudent !== null || $error !== '';

/* MESSAGES */
$statusMap = [
    'added'=>'Student record successfully added.',
    'updated'=>'Student record successfully updated.',
    'archived'=>'Student successfully moved to Archive.',
    'restored'=>'Student record successfully restored.',
    'deleted'=>'Archived student record permanently deleted.'
];
$status = $_GET['status'] ?? '';
if (isset($statusMap[$status])) $success = $statusMap[$status];

/* REPORT SYSTEM: ACTIVE STUDENTS ONLY */
$reportGrade = filter_var($_GET['report_grade'] ?? 0,FILTER_VALIDATE_INT) ?: 0;
$reportSection = filter_var($_GET['report_section'] ?? 0,FILTER_VALIDATE_INT) ?: 0;
$reportMode = $_GET['report_mode'] ?? '';
$reportActive = in_array($reportMode,['present','print'],true);
$reportStudents = [];
$reportTotal = 0;
$reportPage = max(1,(int)($_GET['report_page'] ?? 1));
$reportPageSize = 25;
$reportTotalPages = 1;
$reportOffset = 0;
$selectedGradeName = '';
$selectedSectionName = '';

if ($reportActive && $reportGrade>0 && $reportSection>0) {
    $verify = runStmt(
        $conn,
        'SELECT g.grade_level,s.section_name FROM tblsections s
         INNER JOIN tblgradelevels g ON g.gradelevel_id=s.gradelevel_id
         WHERE s.section_id=? AND g.gradelevel_id=?',
        'ii',[$reportSection,$reportGrade]
    );
    $selectedReport = $verify->get_result()->fetch_assoc();
    $verify->close();

    if ($selectedReport) {
        $selectedGradeName = $selectedReport['grade_level'];
        $selectedSectionName = $selectedReport['section_name'];

        $countReport = runStmt($conn,'SELECT COUNT(*) AS total FROM tblstudents WHERE section_id=? AND is_archived=0','i',[$reportSection]);
        $reportTotal = (int)$countReport->get_result()->fetch_assoc()['total'];
        $countReport->close();

        $reportTotalPages = max(1,(int)ceil($reportTotal/$reportPageSize));
        $reportPage = min($reportPage,$reportTotalPages);
        $reportOffset = ($reportPage-1)*$reportPageSize;

        if ($reportMode==='print') {
            $reportStmt = runStmt(
                $conn,
                'SELECT student_id,lrn,fname,mname,lname,gender,guardian
                 FROM tblstudents WHERE section_id=? AND is_archived=0
                 ORDER BY lname ASC,fname ASC',
                'i',[$reportSection]
            );
        } else {
            $reportStmt = runStmt(
                $conn,
                'SELECT student_id,lrn,fname,mname,lname,gender,guardian
                 FROM tblstudents WHERE section_id=? AND is_archived=0
                 ORDER BY lname ASC,fname ASC LIMIT ? OFFSET ?',
                'iii',[$reportSection,$reportPageSize,$reportOffset]
            );
        }
        $reportResult = $reportStmt->get_result();
        while ($row=$reportResult->fetch_assoc()) $reportStudents[]=$row;
        $reportStmt->close();
    } else {
        $reportActive=false;
        $error='Invalid grade level or section selected.';
    }
} else {
    $reportActive=false;
}

/* ARCHIVE COUNT */
$archiveCount = (int)$conn->query('SELECT COUNT(*) AS total FROM tblstudents WHERE is_archived=1')->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ANRVHS | Student Records</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--navy:#183b63;--blue:#2563eb;--pink:#db2777;--green:#176b45;--bg:#f4f7fb;--border:#e2e8f0;--muted:#64748b}
*{box-sizing:border-box}body{margin:0;padding:0;background:var(--bg);color:#1e293b;font-family:Arial,Helvetica,sans-serif}.app{max-width:1600px;margin:auto;padding:24px}
.school-header{display:flex;align-items:center;gap:18px;background:#123b2a;color:white;padding:18px 25px;border-bottom:5px solid #d6b84c}
.school-logo{width:75px;height:75px;object-fit:contain;border-radius:50%;background:white;padding:4px;flex-shrink:0}
.school-header h2{margin:0;font-size:22px;letter-spacing:.5px}.school-header p{margin:6px 0 0;font-size:13px;color:#e2e9e4}
header{margin-bottom:20px}header h1{margin:0 0 6px;color:var(--navy);font-size:27px}header p{margin:0;color:var(--muted)}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
.stat,.panel{background:white;border:1px solid var(--border);border-radius:12px;box-shadow:0 2px 8px #0f172a0a}.stat{padding:17px 19px}.stat small{display:block;color:var(--muted);margin-bottom:8px}.stat strong{font-size:25px;color:var(--navy)}
.layout{display:grid;grid-template-columns:290px minmax(0,1fr);gap:18px;align-items:start}.sidebar{padding:18px;position:sticky;top:16px}.sidebar h2,.results h2{margin:0 0 15px;color:var(--navy);font-size:17px}
.field{margin-bottom:14px}.field label{display:block;margin-bottom:6px;font-size:12px;font-weight:bold;color:#475569}
input,select{width:100%;min-height:40px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px;background:white;color:#1e293b;font-size:13px}
input:focus,select:focus{outline:2px solid #bfdbfe;border-color:var(--blue)}
.btn{display:inline-flex;justify-content:center;align-items:center;border:0;border-radius:7px;min-height:34px;padding:8px 11px;background:var(--blue);color:white;font-weight:bold;font-size:12px;text-decoration:none;cursor:pointer}
.btn:hover{filter:brightness(.94)}.block{width:100%;min-height:39px}.pink{background:var(--pink)}.muted{background:#64748b}.danger{background:#dc2626}.light{background:#e2e8f0;color:#334155}.green{background:var(--green)}
.actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.inline{display:inline;margin:0}.sidebar-actions{display:grid;gap:9px;margin-top:17px}
.results{min-width:0;overflow:hidden}.results-head{padding:17px 19px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.results-head h2{margin:0}.count{font-size:12px;color:var(--muted)}
.table-wrap{overflow-x:auto}table{width:100%;min-width:980px;border-collapse:collapse}thead{background:#f8fafc}th,td{padding:12px 13px;border-bottom:1px solid var(--border);text-align:left;font-size:12px;white-space:nowrap}th{font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#475569}th a{color:inherit;text-decoration:none}tbody tr:hover{background:#f8fbff}.name{font-weight:bold}.sub{display:block;font-size:11px;color:var(--muted);margin-top:3px}.pill{display:inline-block;padding:4px 8px;border-radius:20px;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:bold}.empty{text-align:center;padding:38px 15px;color:var(--muted)}
.pagination{padding:15px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.page-info{font-size:12px;color:var(--muted)}.page-links{display:flex;align-items:center;gap:5px;flex-wrap:wrap}.page-links a,.page-links span{min-width:31px;text-align:center;padding:8px 9px;border:1px solid var(--border);border-radius:6px;color:#334155;text-decoration:none;font-size:12px}.page-links a.current{background:var(--navy);color:white;border-color:var(--navy)}.page-links a.disabled{pointer-events:none;opacity:.4}
.message{padding:12px 14px;border-radius:8px;margin-bottom:15px;font-size:13px}.success{color:#166534;background:#dcfce7;border:1px solid #86efac}.error{color:#991b1b;background:#fee2e2;border:1px solid #fca5a5}
.modal{display:none;position:fixed;inset:0;z-index:1000;background:#0f172a99;padding:18px;overflow-y:auto}.modal.show{display:flex;align-items:flex-start;justify-content:center}.modal-card{width:100%;max-width:620px;margin:35px auto;background:white;border-radius:13px;padding:22px;box-shadow:0 20px 50px #0003}
.modal-title,.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:17px}.modal-title h2,.modal-header h2{margin:0;color:var(--navy);font-size:19px}.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}
.report-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:18px 0}.report-option{min-height:48px;background:var(--navy);font-size:13px}.report-option:hover{background:var(--blue)}
.report-actions{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:20px 0}.report-present,.report-print{min-height:65px;font-size:14px}.report-present{background:var(--blue)}.report-print{background:var(--green)}.report-description{color:var(--muted);font-size:13px}
#reportSectionStep h3,#reportGradeStep h3,#reportActionStep h3{color:var(--navy);font-size:16px;margin-bottom:15px}
.report-container{margin-bottom:20px}.report-subtitle{color:var(--muted);font-size:13px;margin:6px 0 0}.report-heading{text-align:center;padding:22px 15px}.report-heading h2{color:var(--navy);font-size:19px;margin:0 0 8px}.report-heading h3{font-size:15px;margin:8px 0}.report-heading p{margin:5px;font-size:12px}.report-table{min-width:850px}.report-table th,.report-table td{border:1px solid #cbd5e1;padding:11px}
footer{text-align:center;color:#94a3b8;font-size:11px;padding:22px 0 5px}
.theme-menu{margin-top:10px;padding:12px;background:white;border:1px solid #e2e8f0;border-radius:10px}.theme-menu h4{margin:5px 0 10px;color:#1e293b}.theme-colors{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}.theme-color-btn{padding:9px;border:0;border-radius:7px;color:white;cursor:pointer;font-weight:bold}
.theme-blue{background:#2563eb}.theme-pink{background:#db2777}.theme-red{background:#dc2626}.theme-violet{background:#7c3aed}.theme-yellow{background:#ca8a04}.theme-green{background:#16a34a}.theme-action-btn{width:100%;margin-top:9px;padding:10px;border:0;border-radius:7px;background:#334155;color:white;cursor:pointer;font-weight:bold}
.theme-btn{width:100%;padding:12px 15px;margin-top:8px;border:1px solid var(--blue);border-radius:8px;background:var(--blue);color:white;font-size:14px;font-weight:600;text-align:left;cursor:pointer}
.statistics-content{width:95%;max-width:1100px;max-height:90vh;overflow-y:auto;padding:25px}.statistics-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px}.statistics-card{background:white;border:1px solid #ddd;border-radius:12px;padding:20px;min-width:0}.statistics-card h3{text-align:center;color:#222;margin-bottom:20px}.statistics-card canvas{width:100%!important;max-height:350px}.statistics-footer{text-align:right;margin-top:20px}.statistics-footer button{background:var(--blue);color:white;border:0;border-radius:7px;padding:10px 22px;cursor:pointer}
.individual-search{margin:20px 0}.individual-search label{display:block;margin-bottom:8px;font-weight:600}.individual-result{display:block;width:100%;padding:12px;border:0;border-bottom:1px solid #ddd;text-align:left;cursor:pointer;background:transparent;color:inherit}.individual-result:hover{background:#e5e7eb;color:#111827}
#individualStudentPreview{margin-top:20px;padding:20px;background:white;color:black;border:1px solid #ddd;border-radius:8px}#individualStudentPreview button{margin-top:15px;padding:10px 18px;background:var(--blue);color:white;border:0;border-radius:6px;cursor:pointer}
body.dark-mode,body.dark-mode .app{background:#111827!important;color:#f9fafb!important}body.dark-mode .panel,body.dark-mode .stat,body.dark-mode .theme-menu{background:#1f2937!important;color:#f9fafb!important;border-color:#374151!important}
body.dark-mode input,body.dark-mode select,body.dark-mode table td{background:#374151!important;color:#f9fafb!important;border-color:#4b5563!important}
body.dark-mode h1,body.dark-mode h2,body.dark-mode h3,body.dark-mode h4,body.dark-mode p,body.dark-mode label{color:#f9fafb}body.dark-mode .theme-menu h4{color:#f9fafb}
body.dark-mode #studentModal .modal-card,body.dark-mode #reportModal .modal-card,body.dark-mode #individualReportModal .modal-card{background:#1f2937;color:#f9fafb}
body.dark-mode #studentModal .modal-title h2,body.dark-mode #studentModal label{color:#f9fafb}
body.dark-mode #studentModal input,body.dark-mode #studentModal select{background:#374151;color:white;border-color:#64748b}
body.dark-mode .statistics-card{background:#1f2937;border-color:#374151}body.dark-mode .statistics-card h3{color:#f9fafb}body.dark-mode .statistics-content{background:#111827;color:#f9fafb}
body.dark-mode #individualStudentPreview{background:white;color:black}body.dark-mode .individual-search input{background:#1f2937;color:white}
@media(max-width:850px){.app{padding:13px}.layout{grid-template-columns:1fr}.sidebar{position:static}.stats{grid-template-columns:1fr 1fr}}
@media(max-width:520px){.school-header{padding:12px;gap:12px}.school-logo{width:65px;height:65px}.school-header h2{font-size:15px}.stats{grid-template-columns:1fr}.modal-grid{grid-template-columns:1fr}.modal-actions{flex-direction:column-reverse}.modal-actions .btn{width:100%}.report-grid{grid-template-columns:repeat(2,1fr)}.report-actions,.statistics-grid{grid-template-columns:1fr}}

/* =========================================
   MODERN GRADIENT DESIGN
========================================= */

:root {
    --theme-gradient: linear-gradient(135deg, #2563eb, #60a5fa, #93c5fd);
    --theme-gradient-hover: linear-gradient(135deg, #1d4ed8, #3b82f6);
}

/* Main page background */
body:not(.dark-mode) {
    background: linear-gradient(135deg, var(--bg), #ffffff, var(--bg));
    background-attachment: fixed;
}

/* School heading */
.school-header {
    background: var(--theme-gradient) !important;
    border-bottom: 5px solid #d6b84c;
    box-shadow: 0 8px 22px rgba(0,0,0,0.12);
}

/* Main buttons */
.btn,
.theme-btn,
.report-option,
.report-present,
.report-print,
.statistics-footer button {
    background: var(--theme-gradient) !important;
    border: none;
    color: white !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    transition: all 0.25s ease;
}

/* Button hover effect */
.btn:hover,
.theme-btn:hover,
.report-option:hover,
.report-present:hover,
.report-print:hover,
.statistics-footer button:hover {
    background: var(--theme-gradient-hover) !important;
    transform: translateY(-2px);
    box-shadow: 0 7px 17px rgba(0,0,0,0.18);
    filter: none;
}

/* Dashboard statistic cards */
.stat {
    border: none;
    border-radius: 15px;
    border-top: 4px solid var(--blue);
    box-shadow: 0 5px 18px rgba(0,0,0,0.07);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.stat:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 24px rgba(0,0,0,0.12);
}

/* Main panels */
.panel,
.statistics-card,
.modal-card {
    border-radius: 15px;
    box-shadow: 0 7px 22px rgba(0,0,0,0.07);
}

/* Theme selection buttons */
.theme-blue {
    background: linear-gradient(135deg, #1d4ed8, #60a5fa) !important;
}

.theme-pink {
    background: linear-gradient(135deg, #be185d, #f472b6) !important;
}

.theme-red {
    background: linear-gradient(135deg, #b91c1c, #f87171) !important;
}

.theme-violet {
    background: linear-gradient(135deg, #6d28d9, #a78bfa) !important;
}

.theme-yellow {
    background: linear-gradient(135deg, #a16207, #facc15) !important;
}

.theme-green {
    background: linear-gradient(135deg, #166534, #4ade80) !important;
}

/* Theme menu */
.theme-menu {
    border-radius: 13px;
    box-shadow: 0 5px 18px rgba(0,0,0,0.08);
}

/* Input fields */
input,
select {
    border-radius: 9px;
    transition: border 0.2s ease, box-shadow 0.2s ease;
}

input:focus,
select:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}

/* Table appearance */
table {
    border-radius: 10px;
    overflow: hidden;
}

tbody tr {
    transition: background 0.2s ease;
}

/* Pagination */
.page-links a.current {
    background: var(--theme-gradient) !important;
    border: none;
}

/* Dark mode keeps its dark background */
body.dark-mode,
body.dark-mode .app {
    background: #111827 !important;
}

/* Dark mode panels */
body.dark-mode .panel,
body.dark-mode .stat,
body.dark-mode .theme-menu,
body.dark-mode .statistics-card {
    background: #1f2937 !important;
    border-color: #374151 !important;
}

@media print{
@page{size:landscape;margin:12mm}body{background:white!important;margin:0!important;padding:0!important}
.school-header{display:flex!important;background:white!important;color:black!important;border-bottom:2px solid #c5a52d!important;padding:10px 0!important;margin-bottom:10px!important}
.school-header img{width:65px!important;height:65px!important}.school-header h2{color:black!important;font-size:17px!important}.school-header p{color:#333!important}
.app{display:block!important;max-width:100%!important;margin:0!important;padding:0!important}
.app>header,.app>.stats,.app>.layout,.app>footer,.app>.message,.no-print,#studentModal,#reportModal,#individualReportModal,#statisticsModal{display:none!important}
.app>.report-container{display:block!important;width:100%!important;margin:0!important;padding:0!important;border:0!important;box-shadow:none!important;overflow:visible!important}
.report-container .results-head{display:none!important}.report-heading{display:block!important;text-align:center!important;padding:12px 0!important}
.report-heading h2,.report-heading h3,.report-heading p{color:black!important}.table-wrap{overflow:visible!important}
.report-table{display:table!important;width:100%!important;min-width:0!important;border-collapse:collapse!important}
.report-table thead{display:table-header-group!important}.report-table tbody{display:table-row-group!important}.report-table tr{page-break-inside:avoid!important}
.report-table th,.report-table td{border:1px solid black!important;color:black!important;background:white!important;font-size:9px!important;padding:6px!important;white-space:normal!important}
.report-table th{background:#e5e5e5!important}
}

/* =========================================
   FIXED THEME COLORS AND GRADIENTS
========================================= */

/* School heading */
.school-header {
    background: var(--theme-gradient) !important;
    border-bottom: 5px solid #d6b84c !important;
    box-shadow: 0 8px 22px rgba(0,0,0,0.12);
}

/* Main system buttons */
.btn,
.report-option,
.report-present,
.report-print,
.statistics-footer button {
    background: var(--theme-gradient) !important;
    color: white !important;
    border: none;
    transition: all 0.25s ease;
}

/* Main button hover */
.btn:hover,
.report-option:hover,
.report-present:hover,
.report-print:hover,
.statistics-footer button:hover {
    background: var(--theme-gradient-hover) !important;
    color: white !important;
    transform: translateY(-2px);
}

/* Theme color selection buttons */
.theme-color-btn {
    color: white !important;
    border: none;
    cursor: pointer;
    transition: transform 0.2s ease;
}

.theme-color-btn:hover {
    transform: scale(1.04);
}

/* Individual theme gradients */
.theme-blue {
    background: linear-gradient(135deg, #1d4ed8, #60a5fa) !important;
}

.theme-pink {
    background: linear-gradient(135deg, #be185d, #f472b6) !important;
}

.theme-red {
    background: linear-gradient(135deg, #b91c1c, #f87171) !important;
}

.theme-violet {
    background: linear-gradient(135deg, #6d28d9, #a78bfa) !important;
}

.theme-yellow {
    background: linear-gradient(135deg, #a16207, #facc15) !important;
}

.theme-green {
    background: linear-gradient(135deg, #166534, #4ade80) !important;
}

/* Theme menu */
.theme-menu {
    border-radius: 13px;
    box-shadow: 0 5px 18px rgba(0,0,0,0.08);
}

/* Dark mode background */
body.dark-mode,
body.dark-mode .app {
    background: #111827 !important;
}

</style>
</head>
<body>
<div class="school-header">
<img class="school-logo" src="assets/anrvhs-logo.png" alt="ANRVHS School Logo">
<div><h2>ABULUG NATIONAL RURAL AND VOCATIONAL HIGH SCHOOL</h2><p>Student Records Management System</p></div>
</div>

<div class="app">
<header><h1>ANRVHS Student Records</h1><p>Student Information Management System</p></header>

<section class="stats">
<div class="stat"><small><?php echo $view==='archived'?'Archived Student Records':'Matching Student Records'; ?></small><strong><?php echo number_format($totalRecords); ?></strong></div>
<div class="stat"><small>Available Grade Levels</small><strong><?php echo count($gradeLevels); ?></strong></div>
<div class="stat"><small>Available Sections</small><strong><?php echo count($sections); ?></strong></div>
</section>

<?php if($success!==''):?><div class="message success"><?php echo e($success);?></div><?php endif;?>
<?php if($error!==''):?><div class="message error"><?php echo e($error);?></div><?php endif;?>

<?php if($reportActive):?>
<div class="panel results report-container">
<div class="results-head"><div><h2><?php echo $reportMode==='print'?'Student Report Preview':'Student Records Report';?></h2><p class="report-subtitle"><?php echo e($selectedGradeName.' - '.$selectedSectionName);?></p></div>
<div class="actions no-print"><a class="btn muted" href="<?php echo e(strtok($_SERVER['REQUEST_URI'],'?'));?>">Close Report</a>
<?php if($reportMode==='present'):?><a class="btn pink" href="<?php echo e('?' . http_build_query(['report_grade'=>$reportGrade,'report_section'=>$reportSection,'report_mode'=>'print']));?>">Print Preview</a><?php endif;?>
<?php if($reportMode==='print'):?><button type="button" class="btn green" onclick="window.print()">Print Report</button><?php endif;?></div></div>
<div class="report-heading"><h3>STUDENT MASTER LIST</h3><p>Grade Level: <?php echo e($selectedGradeName);?></p><p>Section: <?php echo e($selectedSectionName);?></p><p>Total Students: <?php echo number_format($reportTotal);?></p></div>
<div class="table-wrap"><table class="report-table"><thead><tr><th>No.</th><th>LRN</th><th>Last Name</th><th>First Name</th><th>Middle Name</th><th>Gender</th><th>Guardian</th></tr></thead><tbody>
<?php if($reportStudents):$number=$reportOffset+1;foreach($reportStudents as $st):?>
<tr><td><?php echo $number++;?></td><td><?php echo e($st['lrn']);?></td><td><?php echo e($st['lname']);?></td><td><?php echo e($st['fname']);?></td><td><?php echo e($st['mname']?:'—');?></td><td><?php echo e($st['gender']?:'—');?></td><td><?php echo e($st['guardian']?:'—');?></td></tr>
<?php endforeach;else:?><tr><td colspan="7" class="empty">No active student records found in this section.</td></tr><?php endif;?>
</tbody></table></div>
<?php if($reportMode==='present'):?><div class="pagination no-print"><div class="page-info">Showing <?php echo $reportTotal?$reportOffset+1:0;?> – <?php echo min($reportOffset+$reportPageSize,$reportTotal);?> of <?php echo number_format($reportTotal);?> students | Page <?php echo $reportPage;?> of <?php echo $reportTotalPages;?></div><div class="page-links">
<a class="<?php echo $reportPage<=1?'disabled':'';?>" href="<?php echo e(reportPageUrl(max(1,$reportPage-1)));?>">Previous</a>
<?php $rFirst=max(1,$reportPage-2);$rLast=min($reportTotalPages,$reportPage+2);for($rp=$rFirst;$rp<=$rLast;$rp++):?><a class="<?php echo $rp===$reportPage?'current':'';?>" href="<?php echo e(reportPageUrl($rp));?>"><?php echo $rp;?></a><?php endfor;?>
<a class="<?php echo $reportPage>=$reportTotalPages?'disabled':'';?>" href="<?php echo e(reportPageUrl(min($reportTotalPages,$reportPage+1)));?>">Next</a>
</div></div><?php endif;?>
</div>
<?php endif;?>

<div class="layout">
<aside class="panel sidebar">
<h2>Search & Controls</h2>
<form method="GET" id="filterForm">
<input type="hidden" name="view" value="<?php echo e($view);?>">
<div class="field"><label for="search">SEARCH STUDENTS</label><input type="search" id="search" name="search" value="<?php echo e($search);?>" placeholder="LRN, name, or guardian..." autocomplete="off"></div>
<div class="field"><label for="grade">GRADE LEVEL</label><select id="grade" name="grade"><option value="0">All Grade Levels</option>
<?php foreach($gradeLevels as $g):?><option value="<?php echo (int)$g['gradelevel_id'];?>" <?php echo $gradeFilter===(int)$g['gradelevel_id']?'selected':'';?>><?php echo e($g['grade_level']);?></option><?php endforeach;?></select></div>
<div class="field"><label for="section">SECTION</label><select id="section" name="section"><option value="0">All Sections</option>
<?php foreach($sections as $s):?><option value="<?php echo (int)$s['section_id'];?>" data-grade="<?php echo (int)$s['gradelevel_id'];?>" <?php echo $sectionFilter===(int)$s['section_id']?'selected':'';?>><?php echo e($s['grade_level'].' - '.$s['section_name']);?></option><?php endforeach;?></select></div>
<div class="field"><label for="per_page">RECORDS PER PAGE</label><select id="per_page" name="per_page"><?php foreach([10,25,50,100] as $n):?><option value="<?php echo $n;?>" <?php echo $pageSize===$n?'selected':'';?>><?php echo $n;?> records</option><?php endforeach;?></select></div>
<input type="hidden" name="sort" value="<?php echo e($sort);?>"><input type="hidden" name="dir" value="<?php echo e(strtolower($direction));?>">
<div class="sidebar-actions">
<a class="btn muted block" href="<?php echo e(strtok($_SERVER['REQUEST_URI'],'?').($view==='archived'?'?view=archived':''));?>">Reset Filters</a>
<?php if($view==='active'):?><button class="btn pink block" type="button" onclick="openStudentModal()">+ Add Student</button><button class="btn green block" type="button" onclick="openReportModal()">Student Reports</button><?php endif;?>
<a class="btn muted block" href="?"><?php echo $view==='archived'?'View Active Students':'View Active Student List';?></a>
<a class="btn danger block" href="?view=archived">Archive (<?php echo $archiveCount;?>)</a>
<button type="button" class="btn green block" onclick="toggleThemeMenu()">
    Interface Theme
</button>

<div class="theme-menu" id="themeMenu" style="display:none">
<h4>Select Background Theme</h4>
<div class="theme-colors">
<button type="button" class="theme-color-btn theme-blue" onclick="applyTheme('blue')">Blue</button><button type="button" class="theme-color-btn theme-pink" onclick="applyTheme('pink')">Pink</button><button type="button" class="theme-color-btn theme-red" onclick="applyTheme('red')">Red</button><button type="button" class="theme-color-btn theme-violet" onclick="applyTheme('violet')">Violet</button><button type="button" class="theme-color-btn theme-yellow" onclick="applyTheme('yellow')">Yellow</button><button type="button" class="theme-color-btn theme-green" onclick="applyTheme('green')">Green</button></div>
<button type="button" class="theme-action-btn" id="darkModeToggle">Dark Mode</button><button type="button" class="theme-action-btn" onclick="resetTheme()">Reset Default</button></div>
<button type="button" class="theme-btn" onclick="openStatisticsDashboard()">📊 Statistics Dashboard</button>
<a class="btn light block" href="?logout=1" onclick="return confirm('Close the student records system?')">Lock System</a>
</div>
</form>
</aside>

<main class="panel results">
<div class="results-head"><h2><?php echo $view==='archived'?'Archived Student Records':'Student Database';?></h2><div class="actions"><span class="count"><?php echo number_format($totalRecords);?> result(s) found</span><a class="btn muted" href="<?php echo e(pageUrl(['page'=>1]));?>">↻ Refresh Data</a></div></div>
<div class="table-wrap"><table><thead><tr>
<?php $columns=['lrn'=>'LRN','name'=>'Student Name','grade'=>'Grade Level','section'=>'Section','gender'=>'Gender','guardian'=>'Guardian'];foreach($columns as $key=>$label):$next=($sort===$key&&$direction==='ASC')?'desc':'asc';$arrow=$sort===$key?($direction==='ASC'?' ▲':' ▼'):'';?>
<th><a href="<?php echo e(pageUrl(['sort'=>$key,'dir'=>$next,'page'=>1]));?>"><?php echo e($label.$arrow);?></a></th><?php endforeach;?><th>ACTIONS</th></tr></thead><tbody>
<?php if($students->num_rows>0):while($st=$students->fetch_assoc()):?>
<tr><td><?php echo e($st['lrn']);?></td><td><span class="name"><?php echo e($st['lname'].', '.$st['fname']);?></span><span class="sub"><?php echo e($st['mname']);?></span></td><td><span class="pill"><?php echo e($st['grade_level']);?></span></td><td><?php echo e($st['section_name']);?></td><td><?php echo e($st['gender']?:'—');?></td><td><?php echo e($st['guardian']?:'—');?></td><td><div class="actions">
<?php if($view==='active'):?><a class="btn light" href="<?php echo e(pageUrl(['edit'=>(int)$st['student_id']]));?>">Edit</a>
<form class="inline" method="POST" onsubmit="return confirm('Move this student to Archive? The record will be preserved and can be restored later.');"><input type="hidden" name="csrf_token" value="<?php echo e($csrf);?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="student_id" value="<?php echo (int)$st['student_id'];?>"><button class="btn danger" type="submit">Archive</button></form>
<?php else:?>
<form class="inline" method="POST"><input type="hidden" name="csrf_token" value="<?php echo e($csrf);?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="student_id" value="<?php echo (int)$st['student_id'];?>"><button class="btn green" type="submit">Restore</button></form>
<form class="inline" method="POST" onsubmit="return confirm('Permanently delete this archived student record? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?php echo e($csrf);?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="student_id" value="<?php echo (int)$st['student_id'];?>"><button class="btn danger" type="submit">Delete</button></form>
<?php endif;?></div></td></tr>
<?php endwhile;else:?><tr><td colspan="7" class="empty">No student records found.</td></tr><?php endif;?>
</tbody></table></div>
<div class="pagination"><div class="page-info">Showing <?php echo $totalRecords?$offset+1:0;?> – <?php echo min($offset+$pageSize,$totalRecords);?> of <?php echo number_format($totalRecords);?> records | Page <?php echo $page;?> of <?php echo $totalPages;?></div>
<nav class="page-links"><a class="<?php echo $page<=1?'disabled':'';?>" href="<?php echo e(pageUrl(['page'=>max(1,$page-1)]));?>">Prev</a>
<?php $first=max(1,$page-2);$last=min($totalPages,$page+2);if($first>1):?><a href="<?php echo e(pageUrl(['page'=>1]));?>">1</a><?php if($first>2):?><span>...</span><?php endif;?><?php endif;?>
<?php for($p=$first;$p<=$last;$p++):?><a class="<?php echo $p===$page?'current':'';?>" href="<?php echo e(pageUrl(['page'=>$p]));?>"><?php echo $p;?></a><?php endfor;?>
<?php if($last<$totalPages):?><?php if($last<$totalPages-1):?><span>...</span><?php endif;?><a href="<?php echo e(pageUrl(['page'=>$totalPages]));?>"><?php echo $totalPages;?></a><?php endif;?>
<a class="<?php echo $page>=$totalPages?'disabled':'';?>" href="<?php echo e(pageUrl(['page'=>min($totalPages,$page+1)]));?>">Next</a></nav></div>
</main>
</div>
<footer>ANRVHS Student Database · Student Records Management</footer>
</div>

<!-- ADD / EDIT STUDENT MODAL -->
<div class="modal <?php echo $showModal?'show':'';?>" id="studentModal" role="dialog" aria-modal="true"><div class="modal-card">
<div class="modal-title"><h2><?php echo $editStudent?'Edit Student Record':'Add New Student';?></h2><button type="button" class="btn light" onclick="closeStudentModal()">Close</button></div>
<form method="POST"><input type="hidden" name="csrf_token" value="<?php echo e($csrf);?>"><input type="hidden" name="action" value="save"><input type="hidden" name="student_id" value="<?php echo (int)($editStudent['student_id']??0);?>">
<div class="modal-grid">
<div class="field"><label for="lrn">LRN *</label><input id="lrn" name="lrn" maxlength="20" required value="<?php echo e($editStudent['lrn']??'');?>"></div>
<div class="field"><label for="fname">FIRST NAME *</label><input id="fname" name="fname" maxlength="50" required value="<?php echo e($editStudent['fname']??'');?>"></div>
<div class="field"><label for="mname">MIDDLE NAME</label><input id="mname" name="mname" maxlength="50" value="<?php echo e($editStudent['mname']??'');?>"></div>
<div class="field"><label for="lname">LAST NAME *</label><input id="lname" name="lname" maxlength="50" required value="<?php echo e($editStudent['lname']??'');?>"></div>
<div class="field"><label for="gender">GENDER</label><select id="gender" name="gender"><option value="">Select gender</option><?php foreach(['Male','Female','Other'] as $g):?><option value="<?php echo e($g);?>" <?php echo (($editStudent['gender']??'')===$g)?'selected':'';?>><?php echo e($g);?></option><?php endforeach;?></select></div>
<div class="field"><label for="guardian">GUARDIAN</label><input id="guardian" name="guardian" maxlength="100" placeholder="Parent or guardian name" value="<?php echo e($editStudent['guardian']??'');?>"></div>
<div class="field" style="grid-column:1/-1"><label for="section_id">GRADE LEVEL & SECTION *</label><select id="section_id" name="section_id" required><option value="">Choose grade level and section</option><?php foreach($sections as $s):?><option value="<?php echo (int)$s['section_id'];?>" <?php echo ((int)($editStudent['section_id']??0)===(int)$s['section_id'])?'selected':'';?>><?php echo e($s['grade_level'].' - '.$s['section_name']);?></option><?php endforeach;?></select></div>
</div><div class="modal-actions"><button type="button" class="btn muted" onclick="closeStudentModal()">Cancel</button><button type="submit" class="btn"><?php echo $editStudent?'Save Changes':'Save Student';?></button></div>
</form></div></div>

<!-- REPORT SELECTION MODAL -->
<div class="modal" id="reportModal" role="dialog" aria-modal="true"><div class="modal-card">
<div class="modal-title"><h2>Student Reports</h2><button type="button" class="btn light" onclick="closeReportModal()">Close</button></div>
<div id="reportGradeStep"><h3>Select Grade Level</h3><div class="report-grid">
<?php foreach($gradeLevels as $g):?><button type="button" class="btn report-option" data-grade="<?php echo (int)$g['gradelevel_id'];?>" data-name="<?php echo e($g['grade_level']);?>" onclick="selectReportGrade(this)"><?php echo e($g['grade_level']);?></button><?php endforeach;?>
</div></div>
<div id="reportSectionStep" style="display:none"><h3 id="selectedGradeTitle">Select Section</h3><div class="report-grid">
<?php foreach($sections as $s):?><button type="button" class="btn report-option section-option" style="display:none" data-grade="<?php echo (int)$s['gradelevel_id'];?>" data-section="<?php echo (int)$s['section_id'];?>" data-name="<?php echo e($s['section_name']);?>" onclick="selectReportSection(this)"><?php echo e($s['section_name']);?></button><?php endforeach;?>
</div><button type="button" class="btn muted" onclick="backToReportGrades()">Back to Grade Levels</button></div>
<div id="reportActionStep" style="display:none"><h3 id="selectedSectionTitle">Report Options</h3><p class="report-description">Choose what you want to do with the selected student records.</p>
<form method="GET"><input type="hidden" name="report_grade" id="reportGradeInput"><input type="hidden" name="report_section" id="reportSectionInput">
<div class="report-actions"><button type="submit" name="report_mode" value="present" class="btn report-present">PRESENT</button><button type="submit" name="report_mode" value="print" class="btn report-print">PRINT CLASS LIST</button></div></form>
<button type="button" class="btn pink block" onclick="showIndividualReport()">🖨 Print Individual Record</button>
<button type="button" class="btn muted" style="margin-top:10px" onclick="backToReportSections()">Back to Sections</button>
</div></div></div>

<!-- INDIVIDUAL STUDENT REPORT MODAL - OUTSIDE REPORT FORM -->
<div class="modal" id="individualReportModal"><div class="modal-card" style="max-width:850px">
<div class="modal-header"><h2>Individual Student Report</h2><button type="button" class="btn light" onclick="closeIndividualReport()">Close</button></div>
<div class="individual-search"><label for="individualStudentSearch">Search Student (LRN or Name)</label><input type="text" id="individualStudentSearch" placeholder="Enter student name or LRN..." autocomplete="off"></div>
<div id="individualStudentResults"></div>
<div id="individualStudentPreview" style="display:none"><div id="individualStudentDetails"></div><button type="button" onclick="printIndividualStudent()">🖨 Print Individual Record</button></div>
</div></div>

<!-- STATISTICS DASHBOARD MODAL -->
<div id="statisticsModal" class="modal"><div class="modal-card statistics-content">
<div class="modal-header"><h2>📊 Enrollment Statistics Dashboard</h2><button type="button" class="btn light" onclick="closeStatisticsDashboard()">×</button></div>
<div class="statistics-grid">
    <div class="statistics-card">
        <h3>Male and Female Students per Grade Level</h3>
        <canvas id="gradePieChart"></canvas>
    </div>

    <div class="statistics-card">
        <h3>Male and Female Students per Section</h3>
        <canvas id="sectionBarChart"></canvas>
    </div>
</div>
<div class="statistics-footer"><button type="button" onclick="closeStatisticsDashboard()">Close Dashboard</button></div>
</div></div>

<script>
/* STUDENT MODAL */
const modal=document.getElementById('studentModal');
function openStudentModal(){modal.classList.add('show');document.getElementById('lrn').focus();}
function closeStudentModal(){modal.classList.remove('show');const u=new URL(location.href);u.searchParams.delete('edit');history.replaceState({},'',u);}
modal.addEventListener('click',e=>{if(e.target===modal)closeStudentModal();});

/* SEARCH AND FILTERS */
const filterForm=document.getElementById('filterForm');
const searchInput=document.getElementById('search');
let searchTimer;
window.addEventListener('DOMContentLoaded',function(){
 if(sessionStorage.getItem('keepSearchFocus')==='true'){sessionStorage.removeItem('keepSearchFocus');searchInput.focus();const n=searchInput.value.length;searchInput.setSelectionRange(n,n);}
});
searchInput.addEventListener('input',function(){clearTimeout(searchTimer);sessionStorage.setItem('keepSearchFocus','true');searchTimer=setTimeout(()=>filterForm.submit(),800);});
['grade','section','per_page'].forEach(id=>document.getElementById(id).addEventListener('change',()=>filterForm.submit()));
const gradeSelect=document.getElementById('grade'),sectionSelect=document.getElementById('section');
function updateSections(){Array.from(sectionSelect.options).forEach((option,index)=>{if(index===0)return;option.hidden=gradeSelect.value!=='0'&&option.dataset.grade!==gradeSelect.value;});if(sectionSelect.selectedOptions[0]?.hidden)sectionSelect.value='0';}
gradeSelect.addEventListener('change',updateSections);updateSections();

/* REPORT NAVIGATION */
const reportModal=document.getElementById('reportModal');
function openReportModal(){reportModal.classList.add('show');document.getElementById('reportGradeStep').style.display='block';document.getElementById('reportSectionStep').style.display='none';document.getElementById('reportActionStep').style.display='none';}
function closeReportModal(){reportModal.classList.remove('show');}
function selectReportGrade(button){
 const gradeId=button.dataset.grade,gradeName=button.dataset.name;
 document.getElementById('reportGradeStep').style.display='none';document.getElementById('reportSectionStep').style.display='block';
 document.getElementById('selectedGradeTitle').textContent='Sections under '+gradeName;document.getElementById('reportGradeInput').value=gradeId;
 document.querySelectorAll('.section-option').forEach(section=>section.style.display=section.dataset.grade===gradeId?'inline-flex':'none');
}
function selectReportSection(button){
 document.getElementById('reportSectionStep').style.display='none';document.getElementById('reportActionStep').style.display='block';
 document.getElementById('selectedSectionTitle').textContent='Selected Section: '+button.dataset.name;
 document.getElementById('reportSectionInput').value=button.dataset.section;
}
function backToReportGrades(){document.getElementById('reportGradeStep').style.display='block';document.getElementById('reportSectionStep').style.display='none';document.getElementById('reportActionStep').style.display='none';}
function backToReportSections(){document.getElementById('reportSectionStep').style.display='block';document.getElementById('reportActionStep').style.display='none';}
reportModal.addEventListener('click',e=>{if(e.target===reportModal)closeReportModal();});

/* INDIVIDUAL REPORT */
let selectedIndividualStudent=null;
function showIndividualReport(){
 closeReportModal();
 const box=document.getElementById('individualReportModal');box.classList.add('show');
 document.getElementById('individualStudentSearch').value='';
 document.getElementById('individualStudentResults').replaceChildren();
 document.getElementById('individualStudentPreview').style.display='none';
 selectedIndividualStudent=null;document.getElementById('individualStudentSearch').focus();
}
function closeIndividualReport(){document.getElementById('individualReportModal').classList.remove('show');}
let individualSearchTimer;
document.getElementById('individualStudentSearch').addEventListener('input',function(){
 clearTimeout(individualSearchTimer);individualSearchTimer=setTimeout(searchIndividualStudent,250);
});
async function searchIndividualStudent(){
 const term=document.getElementById('individualStudentSearch').value.trim();
 const results=document.getElementById('individualStudentResults');
 document.getElementById('individualStudentPreview').style.display='none';selectedIndividualStudent=null;
 if(term.length<2){results.replaceChildren();return;}
 try{
  const response=await fetch('?individual_search='+encodeURIComponent(term),{headers:{'Accept':'application/json'}});
  if(!response.ok)throw new Error('Search request failed');
  const students=await response.json();results.replaceChildren();
  if(!students.length){results.textContent='No active students found.';return;}
  students.forEach(student=>{
   const button=document.createElement('button');button.type='button';button.className='individual-result';
   button.textContent=student.lname+', '+student.fname+' '+(student.mname||'')+' | LRN: '+student.lrn;
   button.addEventListener('click',()=>selectIndividualStudent(student));results.appendChild(button);
  });
 }catch(err){results.textContent='Unable to search student records. Please try again.';}
}
function selectIndividualStudent(student){
 selectedIndividualStudent=student;
 const preview=document.getElementById('individualStudentPreview'),details=document.getElementById('individualStudentDetails');
 details.replaceChildren();
 const heading=document.createElement('h2');heading.textContent='STUDENT INFORMATION RECORD';details.appendChild(heading);
 const fields=[['LRN',student.lrn],['Last Name',student.lname],['First Name',student.fname],['Middle Name',student.mname||'N/A'],['Gender',student.gender||'N/A'],['Grade Level',student.grade_level],['Section',student.section_name],['Guardian',student.guardian||'N/A']];
 fields.forEach(([label,value])=>{const p=document.createElement('p'),strong=document.createElement('strong');strong.textContent=label+': ';p.appendChild(strong);p.appendChild(document.createTextNode(value||'N/A'));details.appendChild(p);});
 preview.style.display='block';
}
function printIndividualStudent(){
 if(!selectedIndividualStudent){alert('Please select a student first.');return;}
 const student=selectedIndividualStudent;
 const printWindow=window.open('','_blank');
 if(!printWindow){alert('Please allow pop-ups to print the student record.');return;}
 const esc=value=>String(value??'N/A').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
 const rows=[['LRN',student.lrn],['Last Name',student.lname],['First Name',student.fname],['Middle Name',student.mname||'N/A'],['Gender',student.gender||'N/A'],['Grade Level',student.grade_level],['Section',student.section_name],['Guardian',student.guardian||'N/A']];
 const tableRows=rows.map(([label,value])=>'<tr><th>'+esc(label)+'</th><td>'+esc(value)+'</td></tr>').join('');
 printWindow.document.open();
 printWindow.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Individual Student Record</title><style>body{font-family:Arial,sans-serif;padding:35px;color:#000;background:#fff}header{text-align:center;border-bottom:2px solid #c5a52d;padding-bottom:15px;margin-bottom:25px}h2{text-align:center;margin:0 0 25px}table{width:100%;border-collapse:collapse}th,td{padding:12px;border:1px solid #777;text-align:left;font-size:14px}th{width:30%;background:#f1f5f9}@media print{body{padding:15mm}}</style></head><body><header><h3>ABULUG NATIONAL RURAL AND VOCATIONAL HIGH SCHOOL</h3><p>Student Records Management System</p></header><h2>STUDENT INFORMATION RECORD</h2><table>'+tableRows+'</table></body></html>');
 printWindow.document.close();printWindow.onload=()=>{printWindow.focus();printWindow.print();};
}


/* =========================================
   THEME SETTINGS - FIXED VERSION
========================================= */

const themeSettings = {
    blue: {
        color: '#2563eb',
        bg: '#eff6ff',
        gradient: 'linear-gradient(135deg, #1d4ed8, #3b82f6, #93c5fd)',
        hover: 'linear-gradient(135deg, #1e40af, #2563eb)'
    },
    pink: {
        color: '#db2777',
        bg: '#fdf2f8',
        gradient: 'linear-gradient(135deg, #be185d, #ec4899, #f9a8d4)',
        hover: 'linear-gradient(135deg, #9d174d, #db2777)'
    },
    red: {
        color: '#dc2626',
        bg: '#fef2f2',
        gradient: 'linear-gradient(135deg, #991b1b, #dc2626, #fca5a5)',
        hover: 'linear-gradient(135deg, #7f1d1d, #b91c1c)'
    },
    violet: {
        color: '#7c3aed',
        bg: '#f5f3ff',
        gradient: 'linear-gradient(135deg, #5b21b6, #8b5cf6, #c4b5fd)',
        hover: 'linear-gradient(135deg, #4c1d95, #6d28d9)'
    },
    yellow: {
        color: '#ca8a04',
        bg: '#fefce8',
        gradient: 'linear-gradient(135deg, #a16207, #eab308, #fde047)',
        hover: 'linear-gradient(135deg, #854d0e, #ca8a04)'
    },
    green: {
        color: '#16a34a',
        bg: '#f0fdf4',
        gradient: 'linear-gradient(135deg, #166534, #22c55e, #86efac)',
        hover: 'linear-gradient(135deg, #14532d, #15803d)'
    }
};

/* APPLY SELECTED THEME */
function applyTheme(name) {
    const theme = themeSettings[name];
    if (!theme) return;

    localStorage.setItem('anrvhsTheme', name);

    document.documentElement.style.setProperty('--blue', theme.color);
    document.documentElement.style.setProperty('--green', theme.color);
    document.documentElement.style.setProperty('--navy', theme.color);
    document.documentElement.style.setProperty('--bg', theme.bg);
    document.documentElement.style.setProperty('--theme-gradient', theme.gradient);
    document.documentElement.style.setProperty('--theme-gradient-hover', theme.hover);

    // Update school header
    const header = document.querySelector('.school-header');

    if (header) {
        header.style.background = theme.gradient;
    }

    // Update page background
    if (!document.body.classList.contains('dark-mode')) {
        document.body.style.background = theme.bg;
    }
}


/* DARK MODE */
const darkModeToggle = document.getElementById('darkModeToggle');

function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');

    const enabled = document.body.classList.contains('dark-mode');

    localStorage.setItem('anrvhsDarkMode', enabled ? 'true' : 'false');

    darkModeToggle.textContent = enabled ? 'Light Mode' : 'Dark Mode';
}

darkModeToggle.addEventListener('click', toggleDarkMode);

/* RESET THEME */
function resetTheme() {
    localStorage.removeItem('anrvhsTheme');

    document.documentElement.style.setProperty('--blue', '#2563eb');
    document.documentElement.style.setProperty('--green', '#176b45');
    document.documentElement.style.setProperty('--navy', '#183b63');
    document.documentElement.style.setProperty('--bg', '#f4f7fb');

    document.documentElement.style.setProperty(
        '--theme-gradient',
        'linear-gradient(135deg, #1d4ed8, #3b82f6, #93c5fd)'
    );

    document.documentElement.style.setProperty(
        '--theme-gradient-hover',
        'linear-gradient(135deg, #1e40af, #2563eb)'
    );

    document.querySelector('.school-header').style.background =
        'linear-gradient(135deg, #1d4ed8, #3b82f6, #93c5fd)';

    if (!document.body.classList.contains('dark-mode')) {
        document.body.style.background = '#f4f7fb';
    }
}

/* RESTORE SAVED SETTINGS */
window.addEventListener('DOMContentLoaded', function() {
    const savedTheme = localStorage.getItem('anrvhsTheme');
    const savedDarkMode = localStorage.getItem('anrvhsDarkMode');

    if (savedTheme && themeSettings[savedTheme]) {
        applyTheme(savedTheme);
    }

    if (savedDarkMode === 'true') {
        document.body.classList.add('dark-mode');
        darkModeToggle.textContent = 'Light Mode';
    }
});


/* =========================================
   STATISTICS CHARTS
   MALE AND FEMALE STUDENT COUNTS
========================================= */

let gradePieChart = null;
let sectionBarChart = null;

// GRADE LEVEL DATA
const gradeLabels = <?php
echo json_encode(
    array_column($gradeStats, 'grade_level'),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
?>;

const gradeMaleValues = <?php
echo json_encode(
    array_map('intval', array_column($gradeStats, 'male_students'))
);
?>;

const gradeFemaleValues = <?php
echo json_encode(
    array_map('intval', array_column($gradeStats, 'female_students'))
);
?>;


// SECTION DATA
const sectionLabels = <?php
echo json_encode(
    array_map(
        function($row) {
            return $row['grade_level'] . ' - ' . $row['section_name'];
        },
        $sectionStats
    ),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
?>;

const sectionMaleValues = <?php
echo json_encode(
    array_map('intval', array_column($sectionStats, 'male_students'))
);
?>;

const sectionFemaleValues = <?php
echo json_encode(
    array_map('intval', array_column($sectionStats, 'female_students'))
);
?>;


// COMMON CHART SETTINGS
function getGenderDatasets(maleValues, femaleValues) {
    return [
        {
            label: 'Male Students',
            data: maleValues,
            backgroundColor: '#2563eb',
            borderColor: '#1d4ed8',
            borderWidth: 1,
            borderRadius: 5
        },
        {
            label: 'Female Students',
            data: femaleValues,
            backgroundColor: '#ec4899',
            borderColor: '#db2777',
            borderWidth: 1,
            borderRadius: 5
        }
    ];
}


// OPEN STATISTICS DASHBOARD
function openStatisticsDashboard() {

    document.getElementById('statisticsModal').classList.add('show');

    if (typeof Chart === 'undefined') {
        alert('Chart.js could not load. Check your internet connection.');
        return;
    }

    if (gradePieChart) {
        gradePieChart.destroy();
    }

    if (sectionBarChart) {
        sectionBarChart.destroy();
    }


    // GRAPH 1: MALE AND FEMALE PER GRADE LEVEL
    const gradeGenderLabels = [];
const gradeGenderValues = [];
const gradeGenderColors = [];

const gradeColors = [
    '#2563eb', '#60a5fa',
    '#16a34a', '#86efac',
    '#f59e0b', '#fcd34d',
    '#9333ea', '#c4b5fd',
    '#ec4899', '#f9a8d4',
    '#ef4444', '#fca5a5'
];

gradeLabels.forEach((grade, index) => {
    gradeGenderLabels.push(grade + ' - Male');
    gradeGenderValues.push(gradeMaleValues[index] || 0);
    gradeGenderColors.push(gradeColors[(index * 2) % gradeColors.length]);

    gradeGenderLabels.push(grade + ' - Female');
    gradeGenderValues.push(gradeFemaleValues[index] || 0);
    gradeGenderColors.push(gradeColors[(index * 2 + 1) % gradeColors.length]);
});

new Chart(document.getElementById('gradePieChart'), {
    type: 'pie',
    data: {
        labels: gradeGenderLabels,
        datasets: [{
            data: gradeGenderValues,
            backgroundColor: gradeGenderColors,
            borderColor: '#ffffff',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.label + ': ' + context.raw + ' students';
                    }
                }
            }
        }
    }
});


    // GRAPH 2: MALE AND FEMALE PER SECTION
    sectionBarChart = new Chart(
        document.getElementById('sectionBarChart'),
        {
            type: 'bar',

            data: {
                labels: sectionLabels,
                datasets: getGenderDatasets(
                    sectionMaleValues,
                    sectionFemaleValues
                )
            },

            options: {
                responsive: true,
                maintainAspectRatio: true,

                scales: {
                    x: {
                        stacked: false,
                        ticks: {
                            autoSkip: false
                        }
                    },

                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        title: {
                            display: true,
                            text: 'Number of Students'
                        }
                    }
                },

                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    }
                }
            }
        }
    );
}


function closeStatisticsDashboard() {
    document.getElementById('statisticsModal').classList.remove('show');
}

/* ESCAPE KEY AND MODAL BACKDROPS */
document.getElementById('individualReportModal').addEventListener('click',e=>{if(e.target.id==='individualReportModal')closeIndividualReport();});
document.getElementById('statisticsModal').addEventListener('click',e=>{if(e.target.id==='statisticsModal')closeStatisticsDashboard();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeStudentModal();closeReportModal();closeIndividualReport();closeStatisticsDashboard();}});
function toggleThemeMenu() {
    const menu = document.getElementById('themeMenu');

    if (!menu) {
        alert('Theme menu not found. Please check the themeMenu ID.');
        return;
    }

    if (menu.style.display === 'none' || menu.style.display === '') {
        menu.style.display = 'block';
    } else {
        menu.style.display = 'none';
    }
}

</script>
</body>
</html>
<?php $conn->close(); ?>
