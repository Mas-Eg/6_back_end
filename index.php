<?php
session_start();

$config = include('db_config.php');
$db = null;

if (!file_exists('db_config.php')) {
    die("Файл db_config.php не найден.");
}
if (!is_array($config) || !isset($config['host'], $config['dbname'], $config['user'], $config['pass'])) {
    die("db_config.php должен возвращать массив с ключами host, dbname, user, pass.");
}

try {
    $db = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8",
        $config['user'],
        $config['pass']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

$errors = [];        
$success = false;
$generatedLogin = $generatedPassword = ''; 
$isAuthenticated = isset($_SESSION['user_id']);
$userData = null;      

if ($isAuthenticated) {
    $stmt = $db->prepare("
        SELECT r.FIO, r.PHONE, r.E_MAIL, r.B_DATE, r.GENDER, r.BIO, r.R_ID,
               GROUP_CONCAT(l.LANG) AS languages
        FROM REQUEST r
        LEFT JOIN CONNECT c ON r.R_ID = c.R_ID
        LEFT JOIN LANGUAGE l ON c.L_ID = l.L_ID
        WHERE r.R_ID = (SELECT request_id FROM users WHERE id = ?)
        GROUP BY r.R_ID
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$isAuthenticated) {
    if (isset($_COOKIE['form_errors'])) {
        $errors = json_decode($_COOKIE['form_errors'], true) ?: [];
        setcookie('form_errors', '', time() - 3600, '/');
    }
    if (isset($_COOKIE['form_values'])) {
        $values = json_decode($_COOKIE['form_values'], true) ?: [];
        $name       = $values['fio'] ?? '';
        $tel        = $values['phone'] ?? '';
        $email      = $values['email'] ?? '';
        $birth_date = $values['birth_date'] ?? '';
        $gender     = $values['gender'] ?? '';
        $bio        = $values['bio'] ?? '';
        $languages  = $values['languages'] ?? [];
        $agreement  = $values['agreement'] ?? false;
        setcookie('form_values', '', time() - 3600, '/');
    }

    if (isset($_COOKIE['form_success'])) {
        $success = true;
        setcookie('form_success', '', time() - 3600, '/');
    }

    if (empty($name) && empty($tel) && empty($email) && empty($birth_date) && empty($gender) && empty($bio) && empty($languages)) {
        if (isset($_COOKIE['form_saved_data'])) {
            $saved = json_decode($_COOKIE['form_saved_data'], true) ?: [];
            $name       = $saved['fio'] ?? '';
            $tel        = $saved['phone'] ?? '';
            $email      = $saved['email'] ?? '';
            $birth_date = $saved['birth_date'] ?? '';
            $gender     = $saved['gender'] ?? '';
            $bio        = $saved['bio'] ?? '';
            $languages  = $saved['languages'] ?? [];
            $agreement  = $saved['agreement'] ?? false;
        }
    }
} else {

    $name = $tel = $email = $birth_date = $gender = $bio = '';
    $languages = [];
    $agreement = false;
}

if ($isAuthenticated && $userData) {
    $name       = $userData['FIO'];
    $tel        = $userData['PHONE'];
    $email      = $userData['E_MAIL'];
    $birth_date = $userData['B_DATE'];
    $gender     = $userData['GENDER'];
    $bio        = $userData['BIO'];
    $languages  = $userData['languages'] ? explode(',', $userData['languages']) : [];
    $agreement  = true; 
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['action']) && $_POST['action'] === 'login') {

        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $db->prepare("SELECT id, password_hash, request_id FROM users WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['request_id'] = $user['request_id'];
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $loginError = "Неверный логин или пароль.";
        }
    } else {

        $name = trim($_POST['fio'] ?? '');
        $tel = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $birth_date = $_POST['birth_date'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $bio = trim($_POST['bio'] ?? '');
        $languages = $_POST['languages'] ?? [];
        $agreement = isset($_POST['agreement']);


        if (empty($name)) {
            $errors['fio'] = "Поле ФИО обязательно для заполнения.";
        } elseif (strlen($name) > 150) {
            $errors['fio'] = "ФИО не должно превышать 150 символов.";
        } elseif (!preg_match('/^[a-zA-Zа-яёА-ЯЁ\s\-]+$/u', $name)) {
            $errors['fio'] = "Допустимы только буквы (русские/латинские), пробелы и дефис.";
        }

        if (empty($tel)) {
            $errors['phone'] = "Поле Телефон обязательно для заполнения.";
        } elseif (!preg_match('/^\+?[0-9\-\s\(\)]+$/', $tel)) {
            $errors['phone'] = "Допустимы цифры, знак '+', дефис, пробелы и круглые скобки.";
        } elseif (strlen(preg_replace('/[^0-9]/', '', $tel)) < 6 || strlen(preg_replace('/[^0-9]/', '', $tel)) > 12) {
            $errors['phone'] = "Номер телефона должен содержать от 6 до 12 цифр.";
        }

        if (empty($email)) {
            $errors['email'] = "Поле Email обязательно для заполнения.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Введите корректный адрес электронной почты.";
        }

        if (empty($birth_date)) {
            $errors['birth_date'] = "Дата рождения обязательна.";
        } else {
            $date = DateTime::createFromFormat('Y-m-d', $birth_date);
            if (!$date || $date->format('Y-m-d') !== $birth_date) {
                $errors['birth_date'] = "Некорректный формат даты.";
            } elseif ($date > new DateTime('today')) {
                $errors['birth_date'] = "Дата рождения не может быть в будущем.";
            }
        }

        if (empty($gender)) {
            $errors['gender'] = "Выберите пол.";
        } elseif (!in_array($gender, ['M', 'F'])) {
            $errors['gender'] = "Недопустимое значение пола.";
        }

        if (!empty($bio)) {
            if (!preg_match('/^[a-zA-Zа-яёА-ЯЁ0-9\s\.\,\!\?\;\:\-\(\)\"\'\r\n]*$/u', $bio)) {
                $errors['bio'] = "Биография может содержать только буквы, цифры, пробелы, знаки препинания и переводы строк.";
            } elseif (strlen($bio) > 5000) {
                $errors['bio'] = "Биография слишком длинная (максимум 5000 символов).";
            }
        }

        if (empty($languages)) {
            $errors['languages'] = "Выберите хотя бы один язык программирования.";
        }

        if (!$agreement) {
            $errors['agreement'] = "Необходимо согласиться с условиями.";
        }

        if (!empty($errors)) {

            if (!$isAuthenticated) {
                setcookie('form_errors', json_encode($errors), 0, '/');
                setcookie('form_values', json_encode([
                    'fio'        => $name,
                    'phone'      => $tel,
                    'email'      => $email,
                    'birth_date' => $birth_date,
                    'gender'     => $gender,
                    'bio'        => $bio,
                    'languages'  => $languages,
                    'agreement'  => $agreement
                ]), 0, '/');
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }

        } else {
            try {
                $db->beginTransaction();

                if ($isAuthenticated) {

                    $requestId = $_SESSION['request_id'];
                    $stmt = $db->prepare("UPDATE REQUEST SET 
                        FIO = :name, PHONE = :tel, E_MAIL = :email, 
                        B_DATE = :birth_date, GENDER = :gender, BIO = :bio 
                        WHERE R_ID = :id");
                    $stmt->execute([
                        ':name'       => $name,
                        ':tel'        => $tel,
                        ':email'      => $email,
                        ':birth_date' => $birth_date,
                        ':gender'     => $gender,
                        ':bio'        => $bio,
                        ':id'         => $requestId
                    ]);

                    $db->prepare("DELETE FROM CONNECT WHERE R_ID = ?")->execute([$requestId]);
                } else {

                    $stmt = $db->prepare("INSERT INTO REQUEST (FIO, PHONE, E_MAIL, B_DATE, GENDER, BIO) 
                                          VALUES (:name, :tel, :email, :birth_date, :gender, :bio)");
                    $stmt->execute([
                        ':name'       => $name,
                        ':tel'        => $tel,
                        ':email'      => $email,
                        ':birth_date' => $birth_date,
                        ':gender'     => $gender,
                        ':bio'        => $bio,
                    ]);
                    $requestId = $db->lastInsertId();

                    $login = 'user_' . uniqid();
                    $rawPassword = bin2hex(random_bytes(4)); 
                    $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);

                    $stmt = $db->prepare("INSERT INTO users (login, password_hash, request_id) VALUES (?, ?, ?)");
                    $stmt->execute([$login, $passwordHash, $requestId]);

                    $generatedLogin = $login;
                    $generatedPassword = $rawPassword;
                }

                $languages = array_unique($languages);
                $getLangId = $db->prepare("SELECT L_ID FROM LANGUAGE WHERE LANG = ?");
                $insertConn = $db->prepare("INSERT INTO CONNECT (R_ID, L_ID) VALUES (?, ?)");

                foreach ($languages as $lang) {
                    $getLangId->execute([$lang]);
                    $row = $getLangId->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $insertConn->execute([$requestId, $row['L_ID']]);
                    }
                }

                $db->commit();

                if (!$isAuthenticated) {

                    setcookie('form_saved_data', json_encode([
                        'fio'        => $name,
                        'phone'      => $tel,
                        'email'      => $email,
                        'birth_date' => $birth_date,
                        'gender'     => $gender,
                        'bio'        => $bio,
                        'languages'  => $languages,
                        'agreement'  => $agreement
                    ]), time() + 31536000, '/');

                    setcookie('generated_credentials', json_encode([
                        'login' => $generatedLogin,
                        'password' => $generatedPassword
                    ]), time() + 60, '/'); 

                    setcookie('form_success', '1', 0, '/');
                } else {
                    $success = true; 
                }

                if (!$isAuthenticated) {
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                }
            } catch (PDOException $e) {
                $db->rollBack();
                $errors['db'] = "Ошибка базы данных: " . $e->getMessage();
                if (!$isAuthenticated) {
                    setcookie('form_errors', json_encode($errors), 0, '/');
                    setcookie('form_values', json_encode([
                        'fio'        => $name,
                        'phone'      => $tel,
                        'email'      => $email,
                        'birth_date' => $birth_date,
                        'gender'     => $gender,
                        'bio'        => $bio,
                        'languages'  => $languages,
                        'agreement'  => $agreement
                    ]), 0, '/');
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                }
            }
        }
    }
}

if (isset($_COOKIE['generated_credentials'])) {
    $creds = json_decode($_COOKIE['generated_credentials'], true);
    $generatedLogin = $creds['login'] ?? '';
    $generatedPassword = $creds['password'] ?? '';
    setcookie('generated_credentials', '', time() - 3600, '/');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>5_back_end</title>
    <link rel="stylesheet" href="style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
<div class="content-header">
    <div class="myform">
        <h2>Форма регистрации / редактирования</h2>

        <?php if ($isAuthenticated): ?>
            <div class="auth-info">
                Вы вошли как пользователь <strong><?= htmlspecialchars($_SESSION['user_id']) ?></strong>.
                <a href="?logout=1" style="margin-left: 20px;">Выйти</a>
            </div>
        <?php else: ?>

            <div class="login-box">
                <h3>Вход для редактирования</h3>
                <?php if (isset($loginError)): ?>
                    <div class="error-message"><?= htmlspecialchars($loginError) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label>Логин:</label>
                        <input type="text" name="login" required>
                    </div>
                    <div class="form-group">
                        <label>Пароль:</label>
                        <input type="password" name="password" required>
                    </div>
                    <input type="submit" value="Войти" class="knopka">
                </form>
            </div>
        <?php endif; ?>

        <?php if ($success || ($generatedLogin && $generatedPassword)): ?>
            <div class="success-message">
                <?php if ($isAuthenticated): ?>
                    <strong>Данные успешно обновлены!</strong>
                <?php else: ?>
                    <strong>Данные успешно сохранены!</strong>
                    <div class="credentials">
                        Ваши данные для входа (сохраните их):<br>
                        <strong>Логин:</strong> <?= htmlspecialchars($generatedLogin) ?><br>
                        <strong>Пароль:</strong> <?= htmlspecialchars($generatedPassword) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="general-errors">
                <strong>Пожалуйста, исправьте следующие ошибки:</strong><br>
                <?php foreach ($errors as $field => $message): ?>
                    - <?= htmlspecialchars($message) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <div class="form-group">
                <label for="name-input">ФИО:</label>
                <input id="name-input" name="fio" type="text" 
                       value="<?= htmlspecialchars($name) ?>" 
                       class="<?= isset($errors['fio']) ? 'error-field' : '' ?>" 
                       placeholder="Иванов Иван Иванович" />
                <?php if (isset($errors['fio'])): ?>
                    <div class="error-message"><?= htmlspecialchars($errors['fio']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="tel-input">Телефон:</label>
                <input id="tel-input" name="phone" type="tel" 
                       value="<?= htmlspecialchars($tel) ?>" 
                       class="<?= isset($errors['phone']) ? 'error-field' : '' ?>" 
                       placeholder="+7 (XXX) XXX-XX-XX" />
                <?php if (isset($errors['phone'])): ?>
                    <div class="error-message"><?= htmlspecialchars($errors['phone']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="email-input">Email:</label>
                <input id="email-input" name="email" type="email" 
                       value="<?= htmlspecialchars($email) ?>" 
                       class="<?= isset($errors['email']) ? 'error-field' : '' ?>" 
                       placeholder="example@domain.ru" />
                <?php if (isset($errors['email'])): ?>
                    <div class="error-message"><?= htmlspecialchars($errors['email']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="birth_date">Дата рождения:</label>
                <input id="birth_date" name="birth_date" 
                       value="<?= htmlspecialchars($birth_date) ?>" 
                       type="date" 
                       class="<?= isset($errors['birth_date']) ? 'error-field' : '' ?>" />
                <?php if (isset($errors['birth_date'])): ?>
                    <div class="error-message"><?= htmlspecialchars($errors['birth_date']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <span>Пол:</span>
                <label>
                    <input type="radio" name="gender" value="M" <?= $gender === 'M' ? 'checked' : '' ?> /> Мужской
                </label>
                <label>
                    <input type="radio" name="gender" value="F" <?= $gender === 'F' ? 'checked' : '' ?> /> Женский
                </label>
                <?php if (isset($errors['gender'])): ?>
                    <div class="error-message"><?= htmlspecialchars($errors['gender']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="lang-select">Любимые языки программирования:</label>
                <select id="lang-select" name="languages[]" multiple 
                        class="<?= isset($errors['languages']) ? 'error-field' : '' ?>">
                    <?php
                    $availableLangs = ['Pascal','C','C++','JavaScript','PHP','Python','Java','Haskel','Clojure','Prolog','Scala','Go'];
                    foreach ($availableLangs as $lang) {
                        $selected = in_array($lang, $languages) ? 'selected' : '';
                        echo "<option value=\"$lang\" $selected>$lang</option>";
                    }
                    ?>
                </select>
                <?php if (isset($errors['languages'])): ?>
                    <div class="error-message"><?= htmlspecialchars($errors['languages']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="bio-textarea">Биография:</label>
                <textarea id="bio-textarea" name="bio" 
                          class="<?= isset($errors['bio']) ? 'error-field' : '' ?>"><?= htmlspecialchars($bio) ?></textarea>
                <?php if (isset($errors['bio'])): ?>
                    <div class="error-message"><?= htmlspecialchars($errors['bio']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="agreement" <?= $agreement ? 'checked' : '' ?> /> С контрактом ознакомлен(-а)
                </label>
                <?php if (isset($errors['agreement'])): ?>
                    <div class="error-message"><?= htmlspecialchars($errors['agreement']) ?></div>
                <?php endif; ?>
            </div>

            <input type="submit" value="<?= $isAuthenticated ? 'Обновить' : 'Сохранить' ?>" class="knopka" />
        </form>
    </div>
</div>
</body>
</html>
