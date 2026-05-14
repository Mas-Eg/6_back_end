<?php
$admin_login    = 'admin';
$admin_password = 'admin'; 

if (
    empty($_SERVER['PHP_AUTH_USER']) ||
    empty($_SERVER['PHP_AUTH_PW'])  ||
    $_SERVER['PHP_AUTH_USER'] !== $admin_login ||
    $_SERVER['PHP_AUTH_PW']   !== $admin_password
) {
    header('WWW-Authenticate: Basic realm="Admin Panel"');
    header('HTTP/1.0 401 Unauthorized');
    die('Доступ запрещён. Необходимо ввести логин и пароль администратора.');
}

$config = include 'db_config.php';
if (!is_array($config) || !isset($config['host'], $config['dbname'], $config['user'], $config['pass'])) {
    die('Ошибка конфигурации БД.');
}

try {
    $db = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8",
        $config['user'],
        $config['pass']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Ошибка подключения к БД: ' . $e->getMessage());
}

function getLanguages($db)
{
    $stmt = $db->query("SELECT L_ID, LANG FROM LANGUAGE ORDER BY LANG");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getLanguagesForRequest($db, $requestId)
{
    $stmt = $db->prepare(
        "SELECT l.LANG FROM CONNECT c JOIN LANGUAGE l ON c.L_ID = l.L_ID WHERE c.R_ID = ?"
    );
    $stmt->execute([$requestId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getStatistics($db)
{
    $stmt = $db->query(
        "SELECT l.LANG, COUNT(DISTINCT c.R_ID) AS cnt
         FROM LANGUAGE l
         LEFT JOIN CONNECT c ON l.L_ID = c.L_ID
         GROUP BY l.L_ID
         ORDER BY cnt DESC, l.LANG"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$message = '';

if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM CONNECT WHERE R_ID = ?")->execute([$id]);
        // Удаляем пользователя (если есть)
        $db->prepare("DELETE FROM users WHERE request_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM REQUEST WHERE R_ID = ?")->execute([$id]);
        $db->commit();
        $message = '<div class="success">Заявка успешно удалена.</div>';
    } catch (PDOException $e) {
        $db->rollBack();
        $message = '<div class="error">Ошибка при удалении: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $requestId = (int)$_POST['id'];
    $fio       = trim($_POST['fio'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $birthDate = $_POST['birth_date'] ?? '';
    $gender    = $_POST['gender'] ?? '';
    $bio       = trim($_POST['bio'] ?? '');
    $languages = $_POST['languages'] ?? [];

    $errors = [];
    if ($fio === '') $errors[] = 'ФИО обязательно';
    if ($phone === '') $errors[] = 'Телефон обязателен';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Некорректный email';
    if ($birthDate === '') $errors[] = 'Дата рождения обязательна';
    if (!in_array($gender, ['M', 'F'])) $errors[] = 'Пол выбран неверно';
    if (empty($languages)) $errors[] = 'Выберите хотя бы один язык';

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $stmt = $db->prepare(
                "UPDATE REQUEST SET FIO=?, PHONE=?, E_MAIL=?, B_DATE=?, GENDER=?, BIO=? WHERE R_ID=?"
            );
            $stmt->execute([$fio, $phone, $email, $birthDate, $gender, $bio, $requestId]);

            $db->prepare("DELETE FROM CONNECT WHERE R_ID=?")->execute([$requestId]);
            $getLangId = $db->prepare("SELECT L_ID FROM LANGUAGE WHERE LANG=?");
            $insertConn = $db->prepare("INSERT INTO CONNECT (R_ID, L_ID) VALUES (?, ?)");
            foreach ($languages as $lang) {
                $getLangId->execute([$lang]);
                $row = $getLangId->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $insertConn->execute([$requestId, $row['L_ID']]);
                }
            }
            $db->commit();
            $message = '<div class="success">Заявка успешно обновлена.</div>';
        } catch (PDOException $e) {
            $db->rollBack();
            $message = '<div class="error">Ошибка при обновлении: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        $message = '<div class="error"><ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $errors)) . '</li></ul></div>';
    }
}

$allRequests = $db->query(
    "SELECT r.R_ID, r.FIO, r.PHONE, r.E_MAIL, r.B_DATE, r.GENDER, r.BIO,
            GROUP_CONCAT(l.LANG ORDER BY l.LANG SEPARATOR ', ') AS languages
     FROM REQUEST r
     LEFT JOIN CONNECT c ON r.R_ID = c.R_ID
     LEFT JOIN LANGUAGE l ON c.L_ID = l.L_ID
     GROUP BY r.R_ID
     ORDER BY r.R_ID DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$editData = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
    $editId = (int)$_GET['id'];
    $stmt = $db->prepare(
        "SELECT * FROM REQUEST WHERE R_ID = ?"
    );
    $stmt->execute([$editId]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editData) {
        $editData['languages'] = getLanguagesForRequest($db, $editId);
    }
}

$statistics = getStatistics($db);
$allLanguages = getLanguages($db); 
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Административная панель</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #f8f8f8; }
        .message { margin-bottom: 15px; }
        .success { background: #d4edda; color: #155724; padding: 10px; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; }
        a.button, button { display: inline-block; padding: 5px 10px; background: #007bff; color: white; text-decoration: none; border: none; cursor: pointer; }
        a.button.danger { background: #dc3545; }
        .edit-form { background: #f9f9f9; padding: 20px; margin-top: 20px; border: 1px solid #ddd; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 4px; font-weight: bold; }
        input, select, textarea { width: 300px; padding: 5px; }
        textarea { height: 80px; }
        .stat-block { margin-top: 40px; }
        .stat-list { list-style: none; padding: 0; }
        .stat-list li { padding: 4px 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>Панель администратора</h1>
    <?= $message ?>

    <h2>Все заявки</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>ФИО</th>
                <th>Телефон</th>
                <th>Email</th>
                <th>Дата рождения</th>
                <th>Пол</th>
                <th>Биография</th>
                <th>Языки</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($allRequests as $req): ?>
            <tr>
                <td><?= $req['R_ID'] ?></td>
                <td><?= htmlspecialchars($req['FIO']) ?></td>
                <td><?= htmlspecialchars($req['PHONE']) ?></td>
                <td><?= htmlspecialchars($req['E_MAIL']) ?></td>
                <td><?= $req['B_DATE'] ?></td>
                <td><?= $req['GENDER'] === 'M' ? 'М' : 'Ж' ?></td>
                <td><?= nl2br(htmlspecialchars($req['BIO'])) ?></td>
                <td><?= htmlspecialchars($req['languages'] ?? '—') ?></td>
                <td>
                    <a href="?action=edit&id=<?= $req['R_ID'] ?>" class="button">✎</a>
                    <a href="?action=delete&id=<?= $req['R_ID'] ?>" class="button danger"
                       onclick="return confirm('Удалить запись?')">✕</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($allRequests)): ?>
            <tr><td colspan="9">Нет данных</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($editData): ?>
        <div class="edit-form">
            <h2>Редактирование заявки #<?= htmlspecialchars($editData['R_ID']) ?></h2>
            <form method="post">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $editData['R_ID'] ?>">
                <div class="form-group">
                    <label>ФИО:</label>
                    <input type="text" name="fio" value="<?= htmlspecialchars($editData['FIO']) ?>">
                </div>
                <div class="form-group">
                    <label>Телефон:</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($editData['PHONE']) ?>">
                </div>
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($editData['E_MAIL']) ?>">
                </div>
                <div class="form-group">
                    <label>Дата рождения:</label>
                    <input type="date" name="birth_date" value="<?= $editData['B_DATE'] ?>">
                </div>
                <div class="form-group">
                    <label>Пол:</label>
                    <select name="gender">
                        <option value="M" <?= $editData['GENDER'] === 'M' ? 'selected' : '' ?>>Мужской</option>
                        <option value="F" <?= $editData['GENDER'] === 'F' ? 'selected' : '' ?>>Женский</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Биография:</label>
                    <textarea name="bio"><?= htmlspecialchars($editData['BIO']) ?></textarea>
                </div>
                <div class="form-group">
                    <label>Языки программирования:</label>
                    <select name="languages[]" multiple size="5">
                        <?php foreach ($allLanguages as $lang): ?>
                            <option value="<?= $lang['LANG'] ?>"
                                <?= in_array($lang['LANG'], $editData['languages']) ? 'selected' : '' ?>>
                                <?= $lang['LANG'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Сохранить изменения</button>
                <a href="admin.php" class="button" style="background:#6c757d;">Отмена</a>
            </form>
        </div>
    <?php endif; ?>

    <!-- Статистика по языкам -->
    <div class="stat-block">
        <h2>Статистика популярности языков</h2>
        <table>
            <thead>
                <tr><th>Язык</th><th>Количество пользователей</th></tr>
            </thead>
            <tbody>
            <?php foreach ($statistics as $stat): ?>
                <tr>
                    <td><?= htmlspecialchars($stat['LANG']) ?></td>
                    <td><?= $stat['cnt'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
