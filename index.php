
<?php
ob_start();

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');

session_start();
date_default_timezone_set('Africa/Addis_Ababa');

/* =========================================================
   SINTAYEHU BEYENE - COMPLETE PORTFOLIO
   PHP + MYSQL + XAMPP - SINGLE FILE
   ========================================================= */

$dbHost = 'localhost';
$dbName = 'sintayehu_portfolio';
$dbUser = 'root';
$dbPass = '';

$uploadDir = __DIR__ . '/uploads/';
$uploadUrl = 'uploads/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/* ---------------- HELPER FUNCTIONS ---------------- */

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }
}

function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

function loggedIn(): bool {
    return isset($_SESSION['admin_id']);
}

function requireAdmin(): void {
    if (!loggedIn()) {
        flash('Please log in first.', 'error');
        redirect('?page=login');
    }
}

function setting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare(
        "SELECT setting_value FROM sb_settings
         WHERE setting_key = ? LIMIT 1"
    );

    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string)$value;
}

function saveSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("
        INSERT INTO sb_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    $stmt->execute([$key, $value]);
}

function uploadImage(string $field, string $folder): ?string {
    global $uploadDir, $uploadUrl;

    if (!isset($_FILES[$field]) ||
        $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be smaller than 5 MB.');
    }

    $tmp = $_FILES[$field]['tmp_name'];
    $mime = mime_content_type($tmp);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif'
    ];

    if (!isset($allowed[$mime]) || !getimagesize($tmp)) {
        throw new RuntimeException(
            'Only JPG, PNG, WEBP and GIF images are allowed.'
        );
    }

    $folderPath = $uploadDir . $folder . '/';

    if (!is_dir($folderPath)) {
        mkdir($folderPath, 0755, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];

    if (!move_uploaded_file($tmp, $folderPath . $filename)) {
        throw new RuntimeException('Could not save uploaded image.');
    }

    return $uploadUrl . $folder . '/' . $filename;
}

function isLocalRequest(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1'], true);
}

/* ---------------- DATABASE CONNECTION ---------------- */

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    /*
       These are the portfolio's own tables.
       Existing sb_ tables and records are retained.
       Other old tables are not dropped.
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sb_admins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sb_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sb_projects (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            technologies TEXT NULL,
            project_link VARCHAR(500) NULL,
            github_link VARCHAR(500) NULL,
            image VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sb_skills (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            level TINYINT UNSIGNED DEFAULT 75,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sb_services (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sb_socials (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            url VARCHAR(500) NOT NULL,
            active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sb_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_name VARCHAR(150) NOT NULL,
            sender_email VARCHAR(190) NOT NULL,
            subject VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sb_activity (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    /* ---------------- DEFAULT PROFILE SETTINGS ---------------- */

    $defaults = [
        'full_name' => 'Sintayehu Beyene',
        'job_title' => 'Full-Stack Developer',
        'introduction' =>
            'I build modern, responsive and professional web applications using frontend and backend technologies.',
        'about' =>
            'I am a Computer Science and Engineering student passionate about software development, web technologies, database systems and solving real-world problems.',
        'education' =>
            'Computer Science and Engineering - CSE',
        'email' => '',
        'phone' => '',
        'location' => 'Ethiopia',
        'logo' => '',
        'profile_photo' => '',
        'cv' => ''
    ];

    foreach ($defaults as $key => $value) {
        $check = $pdo->prepare(
            "SELECT COUNT(*) FROM sb_settings WHERE setting_key=?"
        );
        $check->execute([$key]);

        if ((int)$check->fetchColumn() === 0) {
            saveSetting($pdo, $key, $value);
        }
    }

    /* ---------------- DEFAULT SKILLS ---------------- */

    
if ((int)$pdo->query("SELECT COUNT(*) FROM sb_skills")->fetchColumn() === 0) {

    $skills = [
        'HTML5',
        'CSS3',
        'JavaScript',
        'PHP',
        'MySQL',
        'Python',
        'C++',
        'Java',
        'UI/UX Design',
        'Android Development',
        'Git & GitHub'
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO sb_skills (name) VALUES (?)"
    );

    foreach ($skills as $skill) {
        $stmt->execute([$skill]);
    }
}

    /* ---------------- DEFAULT SERVICES ---------------- */

    if ((int)$pdo->query(
        "SELECT COUNT(*) FROM sb_services"
    )->fetchColumn() === 0) {

        $services = [
            [
                'Website Development',
                'Modern and responsive websites for individuals and businesses.'
            ],
            [
                'Frontend Development',
                'Interactive interfaces using HTML, CSS and JavaScript.'
            ],
            [
                'Backend Development',
                'Secure server-side applications using PHP.'
            ],
            [
                'Database Development',
                'Database design and management using MySQL.'
            ],
            [
                'UI/UX Design',
                'Simple, attractive and user-friendly interfaces.'
            ],
            [
                'Android Development',
                'Mobile application development.'
            ]
        ];

        $stmt = $pdo->prepare(
            "INSERT INTO sb_services (title, description) VALUES (?, ?)"
        );

        foreach ($services as $service) {
            $stmt->execute($service);
        }
    }

    /* ---------------- ADD YOUR CURRENT PROJECTS ---------------- */

    $currentProjects = [
        [
            'Hospital Management System',
            'A hospital management application designed to organize hospital information and support administrative workflows, including patient, staff and hospital record management.',
            'PHP, MySQL, HTML, CSS, JavaScript'
        ],
        [
            'High School Management System (HSMS)',
            'A high school management and student registration system for managing student applications, teachers, classes, attendance, announcements and school information.',
            'PHP, MySQL, HTML, CSS, JavaScript'
        ],
        [
            'Dawuro Zone Website',
            'A public information website about Dawuro Zone, including tourism destinations, culture, local businesses, news, events and community information.',
            'PHP, MySQL, HTML, CSS, JavaScript'
        ]
    ];

    $checkProject = $pdo->prepare(
        "SELECT id FROM sb_projects
         WHERE LOWER(title) = LOWER(?) LIMIT 1"
    );

    $insertProject = $pdo->prepare("
        INSERT INTO sb_projects
        (title, description, technologies, project_link, github_link, image)
        VALUES (?, ?, ?, '', '', NULL)
    ");

    foreach ($currentProjects as $project) {
        $checkProject->execute([$project[0]]);

        if (!$checkProject->fetchColumn()) {
            $insertProject->execute($project);
        }
    }

} catch (Throwable $e) {
    http_response_code(500);

    exit(
        '<h2>Database connection or setup error</h2><pre>' .
        h($e->getMessage()) .
        '</pre><p>Check that MySQL is running and the database exists.</p>'
    );
}

/* ---------------- ACCOUNT STATUS ---------------- */

$adminCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM sb_admins"
)->fetchColumn();

$page = $_GET['page'] ?? 'home';
$action = $_POST['action'] ?? '';

/* ---------------- POST ACTIONS ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        /* CREATE FIRST PRIVATE DEVELOPER ACCOUNT */

        if ($action === 'create_admin') {
            if ($adminCount > 0) {
                throw new RuntimeException(
                    'A developer account already exists.'
                );
            }

            if (!isLocalRequest()) {
                throw new RuntimeException(
                    'First account creation is allowed only on localhost.'
                );
            }

            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!preg_match(
                '/^[a-zA-Z0-9_.-]{3,80}$/',
                $username
            )) {
                throw new RuntimeException(
                    'Username must contain 3-80 valid characters.'
                );
            }

            if (strlen($password) < 6) {
                throw new RuntimeException(
                    'Password must contain at least 6 characters.'
                );
            }

            if ($password !== $confirm) {
                throw new RuntimeException('Passwords do not match.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO sb_admins (username, password_hash)
                 VALUES (?, ?)"
            );

            $stmt->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT)
            ]);

            flash('Developer account created. Please log in.');
            redirect('?page=login');
        }

        /* DEVELOPER LOGIN */

        if ($action === 'login') {
            if (
                !empty($_SESSION['login_blocked_until']) &&
                time() < $_SESSION['login_blocked_until']
            ) {
                throw new RuntimeException(
                    'Too many login attempts. Try again later.'
                );
            }

            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            $stmt = $pdo->prepare(
                "SELECT * FROM sb_admins
                 WHERE username = ? LIMIT 1"
            );

            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if (
                !$admin ||
                !password_verify($password, $admin['password_hash'])
            ) {
                $_SESSION['login_attempts'] =
                    ($_SESSION['login_attempts'] ?? 0) + 1;

                if ($_SESSION['login_attempts'] >= 5) {
                    $_SESSION['login_blocked_until'] = time() + 300;
                    $_SESSION['login_attempts'] = 0;
                }

                throw new RuntimeException(
                    'Incorrect username or password.'
                );
            }

            session_regenerate_id(true);

            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['login_attempts'] = 0;

            flash('Welcome to your developer dashboard.');
            redirect('?page=dashboard');
        }

        /* LOGOUT */

        if ($action === 'logout') {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();

                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            session_destroy();
            redirect('?page=home');
        }

        /* ADMIN-ONLY ACTIONS */

        $adminActions = [
            'save_profile',
            'save_project',
            'delete_project',
            'save_skill',
            'delete_skill',
            'save_service',
            'delete_service',
            'save_social',
            'delete_social',
            'toggle_social',
            'delete_message',
            'read_message',
            'change_password'
        ];

        if (in_array($action, $adminActions, true)) {
            requireAdmin();
        }

        /* SAVE PROFILE */

        if ($action === 'save_profile') {
            $fields = [
                'full_name',
                'job_title',
                'introduction',
                'about',
                'education',
                'email',
                'phone',
                'location'
            ];

            foreach ($fields as $field) {
                saveSetting(
                    $pdo,
                    $field,
                    trim($_POST[$field] ?? '')
                );
            }

            foreach (['logo', 'profile_photo'] as $imageField) {
                $uploaded = uploadImage($imageField, 'profile');

                if ($uploaded) {
                    saveSetting($pdo, $imageField, $uploaded);
                }
            }

            flash('Homepage and profile information updated.');
            redirect('?page=dashboard&tab=profile');
        }

        /* SAVE OR EDIT PROJECT */

        if ($action === 'save_project') {

            /* Verify developer password again */

            $verify = $pdo->prepare(
                "SELECT password_hash FROM sb_admins
                 WHERE id = ? LIMIT 1"
            );

            $verify->execute([$_SESSION['admin_id']]);
            $storedHash = $verify->fetchColumn();

            if (
                !$storedHash ||
                !password_verify(
                    $_POST['developer_password'] ?? '',
                    $storedHash
                )
            ) {
                throw new RuntimeException(
                    'Incorrect developer password. Project was not saved.'
                );
            }

            $id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $technologies = trim($_POST['technologies'] ?? '');
            $projectLink = trim($_POST['project_link'] ?? '');
            $githubLink = trim($_POST['github_link'] ?? '');

            if ($title === '' || $description === '') {
                throw new RuntimeException(
                    'Project title and description are required.'
                );
            }

            if (
                $projectLink !== '' &&
                !filter_var($projectLink, FILTER_VALIDATE_URL)
            ) {
                throw new RuntimeException('Enter a valid project URL.');
            }

            if (
                $githubLink !== '' &&
                !filter_var($githubLink, FILTER_VALIDATE_URL)
            ) {
                throw new RuntimeException('Enter a valid GitHub URL.');
            }

            $image = uploadImage('image', 'projects');

            if ($id > 0) {
                if ($image) {
                    $stmt = $pdo->prepare("
                        UPDATE sb_projects
                        SET title=?, description=?, technologies=?,
                            project_link=?, github_link=?, image=?
                        WHERE id=?
                    ");

                    $stmt->execute([
                        $title,
                        $description,
                        $technologies,
                        $projectLink,
                        $githubLink,
                        $image,
                        $id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE sb_projects
                        SET title=?, description=?, technologies=?,
                            project_link=?, github_link=?
                        WHERE id=?
                    ");

                    $stmt->execute([
                        $title,
                        $description,
                        $technologies,
                        $projectLink,
                        $githubLink,
                        $id
                    ]);
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO sb_projects
                    (title, description, technologies,
                     project_link, github_link, image)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $title,
                    $description,
                    $technologies,
                    $projectLink,
                    $githubLink,
                    $image
                ]);
            }

            $pdo->prepare(
                "INSERT INTO sb_activity (action) VALUES (?)"
            )->execute([
                $id
                    ? 'Updated project: ' . $title
                    : 'Added project: ' . $title
            ]);

            flash('Project saved successfully.');
            redirect('?page=dashboard&tab=projects');
        }

        /* DELETE PROJECT */

        if ($action === 'delete_project') {
            $pdo->prepare(
                "DELETE FROM sb_projects WHERE id=?"
            )->execute([(int)($_POST['id'] ?? 0)]);

            flash('Project deleted.');
            redirect('?page=dashboard&tab=projects');
        }

        /* SAVE SKILL */

        if ($action === 'save_skill') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $level = max(
                0,
                min(100, (int)($_POST['level'] ?? 75))
            );

            if ($name === '') {
                throw new RuntimeException('Skill name is required.');
            }

            if ($id) {
                $stmt = $pdo->prepare(
                    "UPDATE sb_skills SET name=?, level=? WHERE id=?"
                );
                $stmt->execute([$name, $level, $id]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO sb_skills (name, level) VALUES (?, ?)"
                );
                $stmt->execute([$name, $level]);
            }

            flash('Skill saved.');
            redirect('?page=dashboard&tab=skills');
        }

        /* DELETE SKILL */

        if ($action === 'delete_skill') {
            $pdo->prepare(
                "DELETE FROM sb_skills WHERE id=?"
            )->execute([(int)($_POST['id'] ?? 0)]);

            flash('Skill deleted.');
            redirect('?page=dashboard&tab=skills');
        }

        /* SAVE SERVICE */

        if ($action === 'save_service') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($title === '') {
                throw new RuntimeException(
                    'Service title is required.'
                );
            }

            if ($id) {
                $stmt = $pdo->prepare(
                    "UPDATE sb_services
                     SET title=?, description=? WHERE id=?"
                );
                $stmt->execute([$title, $description, $id]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO sb_services (title, description)
                     VALUES (?, ?)"
                );
                $stmt->execute([$title, $description]);
            }

            flash('Service saved.');
            redirect('?page=dashboard&tab=services');
        }

        /* DELETE SERVICE */

        if ($action === 'delete_service') {
            $pdo->prepare(
                "DELETE FROM sb_services WHERE id=?"
            )->execute([(int)($_POST['id'] ?? 0)]);

            flash('Service deleted.');
            redirect('?page=dashboard&tab=services');
        }

        /* SAVE SOCIAL LINK */

        if ($action === 'save_social') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $url = trim($_POST['url'] ?? '');

            if (
                $name === '' ||
                !filter_var($url, FILTER_VALIDATE_URL)
            ) {
                throw new RuntimeException(
                    'Enter a social media name and valid URL.'
                );
            }

            if ($id) {
                $stmt = $pdo->prepare(
                    "UPDATE sb_socials SET name=?, url=? WHERE id=?"
                );
                $stmt->execute([$name, $url, $id]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO sb_socials (name, url) VALUES (?, ?)"
                );
                $stmt->execute([$name, $url]);
            }

            flash('Social media link saved.');
            redirect('?page=dashboard&tab=socials');
        }

        /* DELETE SOCIAL LINK */

        if ($action === 'delete_social') {
            $pdo->prepare(
                "DELETE FROM sb_socials WHERE id=?"
            )->execute([(int)($_POST['id'] ?? 0)]);

            flash('Social media link deleted.');
            redirect('?page=dashboard&tab=socials');
        }

        /* ACTIVATE / DEACTIVATE SOCIAL */

        if ($action === 'toggle_social') {
            $pdo->prepare(
                "UPDATE sb_socials SET active = 1 - active WHERE id=?"
            )->execute([(int)($_POST['id'] ?? 0)]);

            flash('Social media status updated.');
            redirect('?page=dashboard&tab=socials');
        }

        /* CONTACT FORM */

        if ($action === 'contact') {
            $name = trim($_POST['sender_name'] ?? '');
            $email = trim($_POST['sender_email'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $message = trim($_POST['message'] ?? '');

            if (
                $name === '' ||
                !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                $subject === '' ||
                $message === ''
            ) {
                throw new RuntimeException(
                    'Complete all contact form fields correctly.'
                );
            }

            $stmt = $pdo->prepare("
                INSERT INTO sb_messages
                (sender_name, sender_email, subject, message)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $name,
                $email,
                $subject,
                $message
            ]);

            flash('Your message has been sent successfully.');
            redirect('?page=home#contact');
        }

        /* DELETE MESSAGE */

        if ($action === 'delete_message') {
            $pdo->prepare(
                "DELETE FROM sb_messages WHERE id=?"
            )->execute([(int)($_POST['id'] ?? 0)]);

            flash('Message deleted.');
            redirect('?page=dashboard&tab=messages');
        }

        /* MARK MESSAGE READ */

        if ($action === 'read_message') {
            $pdo->prepare(
                "UPDATE sb_messages SET is_read=1 WHERE id=?"
            )->execute([(int)($_POST['id'] ?? 0)]);

            flash('Message marked as read.');
            redirect('?page=dashboard&tab=messages');
        }

        /* CHANGE DEVELOPER PASSWORD */

        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            $stmt = $pdo->prepare(
                "SELECT password_hash FROM sb_admins WHERE id=?"
            );

            $stmt->execute([$_SESSION['admin_id']]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($current, $hash)) {
                throw new RuntimeException(
                    'Current password is incorrect.'
                );
            }

            if (strlen($new) < 12 || $new !== $confirm) {
                throw new RuntimeException(
                    'New passwords must match and contain at least 12 characters.'
                );
            }

            $stmt = $pdo->prepare(
                "UPDATE sb_admins SET password_hash=? WHERE id=?"
            );

            $stmt->execute([
                password_hash($new, PASSWORD_DEFAULT),
                $_SESSION['admin_id']
            ]);

            flash('Password changed successfully.');
            redirect('?page=dashboard&tab=account');
        }

    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');

        $back = $_SERVER['HTTP_REFERER'] ?? '?page=home';
        redirect($back);
    }
}

/* ---------------- LOAD WEBSITE DATA ---------------- */

$profile = [];

foreach ($defaults as $key => $value) {
    $profile[$key] = setting($pdo, $key, $value);
}

$projects = $pdo->query(
    "SELECT * FROM sb_projects ORDER BY id DESC"
)->fetchAll();

$skills = $pdo->query(
    "SELECT * FROM sb_skills ORDER BY id ASC"
)->fetchAll();

$services = $pdo->query(
    "SELECT * FROM sb_services ORDER BY id ASC"
)->fetchAll();

$socials = $pdo->query(
    "SELECT * FROM sb_socials WHERE active=1 ORDER BY id ASC"
)->fetchAll();

$allSocials = $pdo->query(
    "SELECT * FROM sb_socials ORDER BY id DESC"
)->fetchAll();

$messages = $pdo->query(
    "SELECT * FROM sb_messages ORDER BY id DESC"
)->fetchAll();

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$tab = $_GET['tab'] ?? 'overview';

$editProject = null;
$editSkill = null;
$editService = null;
$editSocial = null;

if (loggedIn() && $page === 'dashboard') {
    if (isset($_GET['edit_project'])) {
        $stmt = $pdo->prepare(
            "SELECT * FROM sb_projects WHERE id=?"
        );
        $stmt->execute([(int)$_GET['edit_project']]);
        $editProject = $stmt->fetch();
    }

    if (isset($_GET['edit_skill'])) {
        $stmt = $pdo->prepare(
            "SELECT * FROM sb_skills WHERE id=?"
        );
        $stmt->execute([(int)$_GET['edit_skill']]);
        $editSkill = $stmt->fetch();
    }

    if (isset($_GET['edit_service'])) {
        $stmt = $pdo->prepare(
            "SELECT * FROM sb_services WHERE id=?"
        );
        $stmt->execute([(int)$_GET['edit_service']]);
        $editService = $stmt->fetch();
    }

    if (isset($_GET['edit_social'])) {
        $stmt = $pdo->prepare(
            "SELECT * FROM sb_socials WHERE id=?"
        );
        $stmt->execute([(int)$_GET['edit_social']]);
        $editSocial = $stmt->fetch();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= h($profile['full_name']) ?> | <?= h($profile['job_title']) ?></title>
<meta name="description" content="<?= h($profile['introduction']) ?>">

<style>
:root {
    --green: #39ff88;
    --green2: #00d978;
    --purple: #b34cff;
    --purple2: #7b2cff;
    --gold: #ffd166;
    --bg: #090711;
    --panel: rgba(20,15,34,.93);
    --text: #f7f4ff;
    --muted: #bdb4ce;
    --danger: #ff5577;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html {
    scroll-behavior: smooth;
}

body {
    background:
        radial-gradient(ellipse at 15% 10%, rgba(123,44,255,.20), transparent 40%),
        radial-gradient(ellipse at 90% 40%, rgba(0,217,120,.12), transparent 38%),
        var(--bg);
    color: var(--text);
    font-family: Inter, Segoe UI, Arial, sans-serif;
    line-height: 1.7;
    overflow-x: hidden;
}

a {
    color: inherit;
    text-decoration: none;
}

button, input, textarea, select {
    font: inherit;
}

.container {
    width: min(1160px, 92%);
    margin: auto;
}

#particles {
    position: fixed;
    inset: 0;
    z-index: -1;
    pointer-events: none;
}

.neon {
    border: 1px solid rgba(179,76,255,.7);
    box-shadow:
        0 0 10px rgba(179,76,255,.22),
        inset 0 0 10px rgba(57,255,136,.06);
    border-radius: 18px;
}

.neon:hover {
    border-color: var(--green);
    box-shadow:
        0 0 18px rgba(57,255,136,.28),
        0 0 25px rgba(179,76,255,.16);
}

header {
    position: sticky;
    top: 0;
    z-index: 20;
    background: rgba(9,7,17,.94);
    backdrop-filter: blur(15px);
    border-bottom: 1px solid rgba(255,209,102,.4);
}

.nav {
    min-height: 76px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 900;
}

.brand img {
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: 50%;
    border: 2px solid var(--green);
}

.brand span {
    color: var(--gold);
}

nav {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

nav a {
    color: var(--muted);
    font-size: 14px;
    transition: .2s;
}

nav a:hover {
    color: var(--green);
}

.language-select {
    width: auto;
    min-width: 110px;
    padding: 9px 12px;
    border: 1px solid var(--gold);
    border-radius: 11px;
    background: #100c1b;
    color: var(--green);
    font-weight: 800;
    cursor: pointer;
}

.language-select:focus {
    outline: none;
    border-color: var(--green);
    box-shadow: 0 0 15px rgba(57,255,136,.3);
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    padding: 11px 20px;
    border-radius: 12px;
    cursor: pointer;
    border: 1px solid var(--green);
    color: var(--green);
    background: rgba(57,255,136,.08);
    font-weight: 750;
    transition: .25s;
}

.btn:hover {
    background: var(--green);
    color: #07110c;
    box-shadow: 0 0 20px rgba(57,255,136,.4);
}

.btn.gold {
    border-color: var(--gold);
    color: var(--gold);
    background: rgba(255,209,102,.08);
}

.btn.purple {
    border-color: var(--purple);
    color: #e1b8ff;
    background: rgba(179,76,255,.12);
}

.btn.danger {
    border-color: var(--danger);
    color: var(--danger);
}

section {
    padding: 85px 0;
}

.section-title {
    text-align: center;
    margin-bottom: 35px;
}

.section-title h2 {
    font-size: clamp(28px,4vw,42px);
    color: var(--gold);
}

.section-title p {
    color: var(--muted);
}

.hero {
    min-height: 680px;
    display: flex;
    align-items: center;
}

.hero-grid {
    display: grid;
    grid-template-columns: 1.25fr .75fr;
    align-items: center;
    gap: 40px;
}

.kicker {
    color: var(--green);
    letter-spacing: 4px;
    font-size: 12px;
    font-weight: 800;
}

.hero h1 {
    font-size: clamp(42px,7vw,76px);
    line-height: 1.12;
    margin: 16px 0;
}

.hero h1 span {
    color: var(--gold);
    text-shadow: 0 0 20px rgba(255,209,102,.25);
}

.job {
    color: var(--green);
    font-size: clamp(20px,3vw,28px);
    font-weight: 800;
    min-height: 45px;
}

.hero p {
    max-width: 680px;
    color: var(--muted);
    margin: 20px 0 28px;
}

.hero-photo {
    width: min(340px,100%);
    aspect-ratio: 1;
    margin: auto;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--green);
    box-shadow:
        0 0 25px rgba(57,255,136,.3),
        0 0 60px rgba(179,76,255,.25);
}

.placeholder {
    display: grid;
    place-items: center;
    font-size: 110px;
    background: linear-gradient(135deg,#21123b,#063b2b);
}

.actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

/* MOVING SKILLS AND SERVICES */

.marquee-wrap,
.social-marquee-wrap {
    width: 100%;
    overflow: hidden;
    position: relative;
    padding: 17px 0;
    margin: 20px 0;
    border-top: 1px solid rgba(255,209,102,.45);
    border-bottom: 1px solid rgba(179,76,255,.45);
    background: rgba(16,12,27,.75);
    box-shadow: 0 0 18px rgba(179,76,255,.10);
}

.marquee {
    display: flex;
    width: max-content;
    align-items: center;
    animation: moveRight 28s linear infinite;
}

.marquee span {
    color: var(--green);
    font-weight: 850;
    font-size: 16px;
    padding: 10px 30px;
    white-space: nowrap;
    border-right: 1px solid rgba(255,209,102,.25);
    text-shadow: 0 0 10px rgba(57,255,136,.3);
}

.marquee-wrap:hover .marquee,
.social-marquee-wrap:hover .social-marquee {
    animation-play-state: paused;
}

@keyframes moveRight {
    from {
        transform: translateX(-50%);
    }
    to {
        transform: translateX(0);
    }
}

.grid {
    display: grid;
    grid-template-columns: repeat(3,1fr);
    gap: 22px;
}

.card {
    background: var(--panel);
    padding: 24px;
}

.card h3 {
    color: var(--green);
    margin-bottom: 10px;
}

.card p {
    color: var(--muted);
}

.skills-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 20px;
    margin-top: 30px;
}

.skill-card {
    position: relative;
    padding: 25px 15px;
    text-align: center;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(34,197,94,0.25);
    border-radius: 18px;
    transition: all 0.3s ease;
    overflow: hidden;
}

.skill-card:hover {
    transform: translateY(-8px);
    border-color: #22c55e;
    box-shadow: 0 10px 30px rgba(34,197,94,0.18);
}

.skill-card .skill-icon {
    font-size: 38px;
    margin-bottom: 14px;
    color: #22c55e;
}

.skill-card h3 {
    font-size: 17px;
    margin: 0;
    font-weight: 700;
    color: var(--text, #fff);
}

.skill-card span {
    display: block;
    margin-top: 8px;
    font-size: 12px;
    color: #22c55e;
    letter-spacing: 1px;
}


.project-card {
    overflow: hidden;
    transition: transform .3s, box-shadow .3s;
}

.project-card:hover {
    transform: translateY(-8px);
    box-shadow:
        0 0 22px rgba(57,255,136,.2),
        0 0 35px rgba(179,76,255,.2);
}

.project-image {
    width: 100%;
    height: 190px;
    object-fit: cover;
    border-radius: 12px;
    margin-bottom: 16px;
}

.project-placeholder {
    height: 190px;
    display: grid;
    place-items: center;
    font-size: 60px;
    margin-bottom: 16px;
    background: #201331;
    border-radius: 12px;
}

.tags {
    color: var(--gold);
    font-size: 13px;
    margin: 12px 0;
}

/* SOCIAL LINKS MARQUEE */

.social-marquee {
    display: flex;
    width: max-content;
    align-items: center;
    gap: 18px;
    animation: moveRight 32s linear infinite;
}

.social-chip {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    padding: 12px 23px;
    margin: 0 8px;
    border: 1px solid var(--purple);
    border-radius: 30px;
    background: linear-gradient(
        120deg,
        rgba(179,76,255,.15),
        rgba(57,255,136,.08)
    );
    color: var(--green);
    font-weight: 850;
    white-space: nowrap;
    box-shadow: 0 0 14px rgba(179,76,255,.18);
    transition: .3s;
}

.social-chip:hover {
    color: var(--gold);
    border-color: var(--green);
    transform: translateY(-4px);
    box-shadow: 0 0 22px rgba(57,255,136,.35);
}

.social-icon {
    width: 32px;
    height: 32px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: rgba(57,255,136,.13);
    border: 1px solid var(--green);
    color: var(--gold);
    font-size: 13px;
}

form {
    display: grid;
    gap: 13px;
}

input,
textarea,
select {
    width: 100%;
    padding: 13px 15px;
    border-radius: 11px;
    border: 1px solid rgba(179,76,255,.7);
    background: #100c1b;
    color: white;
    outline: none;
}

input:focus,
textarea:focus,
select:focus {
    border-color: var(--green);
    box-shadow: 0 0 12px rgba(57,255,136,.2);
}

input[type="file"] {
    padding: 10px;
}

textarea {
    min-height: 130px;
    resize: vertical;
}

label {
    color: var(--gold);
    font-size: 14px;
}

footer {
    border-top: 1px solid var(--gold);
    padding: 40px 0;
    background: #080610;
}

.footer-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
}

.socials {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.socials a {
    color: var(--green);
    padding: 9px 13px;
    border: 1px solid var(--purple);
    border-radius: 12px;
}

.socials a:hover {
    color: var(--gold);
    border-color: var(--green);
}

.notice {
    padding: 13px 18px;
    border-radius: 12px;
    margin: 15px auto;
    width: min(1160px,92%);
}

.notice.success {
    border: 1px solid var(--green);
    color: var(--green);
}

.notice.error {
    border: 1px solid var(--danger);
    color: #ff9ab0;
}

/* ADMIN DASHBOARD */

.admin-layout {
    display: grid;
    grid-template-columns: 230px 1fr;
    gap: 22px;
    padding: 35px 0;
}

.sidebar {
    padding: 20px;
    align-self: start;
    position: sticky;
    top: 95px;
}

.sidebar a {
    display: block;
    padding: 10px;
    border-radius: 9px;
    color: var(--muted);
}

.sidebar a:hover,
.sidebar a.active {
    background: rgba(57,255,136,.1);
    color: var(--green);
}

.panel {
    padding: 25px;
    min-width: 0;
}

.panel h2 {
    color: var(--gold);
    margin-bottom: 20px;
}

.admin-form {
    max-width: 800px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 600px;
}

th, td {
    text-align: left;
    padding: 12px;
    border-bottom: 1px solid rgba(179,76,255,.3);
}

th {
    color: var(--gold);
}

td {
    color: var(--muted);
}

.inline-form {
    display: inline;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4,1fr);
    gap: 14px;
    margin-bottom: 24px;
}

.stat {
    padding: 20px;
    background: #171025;
    border: 1px solid var(--purple);
    border-radius: 14px;
}

.stat strong {
    display: block;
    font-size: 27px;
    color: var(--green);
}

.small {
    font-size: 13px;
    color: var(--muted);
}

.auth-wrap {
    min-height: 75vh;
    display: grid;
    place-items: center;
    padding: 40px 15px;
}

.auth-card {
    width: min(480px,100%);
    padding: 30px;
}

.auth-card h1 {
    color: var(--gold);
    margin-bottom: 20px;
}

@media(max-width:850px) {
    .hero-grid {
        grid-template-columns: 1fr;
        text-align: center;
    }

    .hero-photo {
        max-width: 260px;
    }

    .actions {
        justify-content: center;
    }

    .grid {
        grid-template-columns: repeat(2,1fr);
    }

    .admin-layout {
        grid-template-columns: 1fr;
    }

    .sidebar {
        position: static;
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .stats {
        grid-template-columns: repeat(2,1fr);
    }
}

@media(max-width:560px) {
    .nav {
        align-items: flex-start;
        flex-direction: column;
        padding: 12px 0;
    }

    nav {
        gap: 12px;
    }

    .grid {
        grid-template-columns: 1fr;
    }

    section {
        padding: 60px 0;
    }

    .stats {
        grid-template-columns: 1fr 1fr;
    }
}

@media(prefers-reduced-motion: reduce) {
    .marquee,
    .social-marquee {
        animation: none;
    }

    html {
        scroll-behavior: auto;
    }
}

.project-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 20px;
}

.project-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 20px;
    color: #fff !important;
    background: #16a34a;
    border-radius: 10px;
    text-decoration: none !important;
    cursor: pointer;
    position: relative;
    z-index: 10;
    transition: 0.3s ease;
}

.project-btn.purple {
    background: #7c3aed;
}

.project-btn:hover {
    transform: translateY(-3px);
    opacity: 0.9;
}

.project-image {
    width: 100%;
    height: 220px;
    object-fit: cover;
    border-radius: 12px;
}

.project-placeholder {
    min-height: 180px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 55px;
    background: rgba(100, 100, 100, 0.1);
    border-radius: 12px;
}

</style>
</head>

<body>

<canvas id="particles"></canvas>

<?php if ($flashMessage): ?>
    <div class="notice <?= h($flashMessage['type']) ?>">
        <?= h($flashMessage['message']) ?>
    </div>
<?php endif; ?>

<?php
/* =========================================================
   FIRST DEVELOPER ACCOUNT PAGE
   ========================================================= */
?>

<?php if ($adminCount === 0): ?>

<div class="auth-wrap">
    <div class="auth-card neon">
        <h1>Create Your Developer Account</h1>

        <p class="small">
            Create your private developer account.
            This first account can only be created from localhost.
            Password must contain at least 6 characters.
        </p>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_admin">

            <label>Developer Username</label>
            <input
                name="username"
                required
                minlength="3"
                maxlength="80"
                autocomplete="username"
            >

            <label>Password</label>
            <input
                type="password"
                name="password"
                required
                minlength="6"
                autocomplete="new-password"
            >

            <label>Confirm Password</label>
            <input
                type="password"
                name="confirm_password"
                required
                minlength="6"
                autocomplete="new-password"
            >

            <button class="btn" type="submit">
                Create Developer Account
            </button>
        </form>
    </div>
</div>

<?php elseif ($page === 'login'): ?>

<div class="auth-wrap">
    <div class="auth-card neon">
        <h1>Developer Login</h1>

        <p class="small">
            Private access to your portfolio dashboard.
        </p>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="login">

            <label>Username</label>
            <input name="username" required autocomplete="username">

            <label>Password</label>
            <input
                type="password"
                name="password"
                required
                autocomplete="current-password"
            >

            <button class="btn" type="submit">
                Secure Login
            </button>

            <a class="btn purple" href="?page=home">
                Back to Homepage
            </a>
        </form>
    </div>
</div>

<?php elseif ($page === 'dashboard'): ?>

<?php requireAdmin(); ?>

<header>
    <div class="container nav">
        <a class="brand" href="?page=home">
            <?php if ($profile['logo']): ?>
                <img src="<?= h($profile['logo']) ?>" alt="Logo">
            <?php endif; ?>
            <span>Developer Dashboard</span>
        </a>

        <nav>
            <a href="?page=home">View Website</a>

            <form method="post" class="inline-form">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="logout">

                <button class="btn danger" type="submit">
                    Logout
                </button>
            </form>
        </nav>
    </div>
</header>

<div class="container admin-layout">

    <aside class="sidebar neon">
        <?php
        $tabs = [
            'overview' => 'Dashboard',
            'profile' => 'Homepage / About',
            'projects' => 'Projects',
            'skills' => 'Skills',
            'services' => 'Services',
            'socials' => 'Social Media',
            'messages' => 'Messages',
            'account' => 'Account'
        ];

        foreach ($tabs as $key => $label):
        ?>
            <a
                class="<?= $tab === $key ? 'active' : '' ?>"
                href="?page=dashboard&tab=<?= h($key) ?>"
            >
                <?= h($label) ?>
            </a>
        <?php endforeach; ?>
    </aside>

    <main class="panel neon">

    <?php if ($tab === 'overview'): ?>

        <h2>
            Welcome, <?= h($_SESSION['admin_username']) ?>
        </h2>

        <div class="stats">
            <div class="stat">
                <strong><?= count($projects) ?></strong>
                Projects
            </div>

            <div class="stat">
                <strong><?= count($skills) ?></strong>
                Skills
            </div>

            <div class="stat">
                <strong><?= count($services) ?></strong>
                Services
            </div>

            <div class="stat">
                <strong><?= count($messages) ?></strong>
                Messages
            </div>
        </div>

        <h2>Portfolio Management</h2>

        <p>
            Manage your homepage, profile, logo, projects, skills,
            services, social media links and contact messages.
        </p>

        <br>

        <div class="actions">
            <a class="btn" href="?page=dashboard&tab=profile">
                Edit Homepage
            </a>

            <a class="btn purple" href="?page=dashboard&tab=projects">
                Manage Projects
            </a>

            <a class="btn gold" href="?page=dashboard&tab=socials">
                Manage Social Media
            </a>
        </div>

    <?php elseif ($tab === 'profile'): ?>

        <h2>Edit Homepage, Logo, Profile and About Me</h2>

        <form
            class="admin-form"
            method="post"
            enctype="multipart/form-data"
        >
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_profile">

            <label>Full Name</label>
            <input
                name="full_name"
                value="<?= h($profile['full_name']) ?>"
                required
            >

            <label>Professional Title</label>
            <input
                name="job_title"
                value="<?= h($profile['job_title']) ?>"
            >

            <label>Homepage Introduction</label>
            <textarea name="introduction"><?= h($profile['introduction']) ?></textarea>

            <label>About Me</label>
            <textarea name="about"><?= h($profile['about']) ?></textarea>

            <label>Education</label>
            <textarea name="education"><?= h($profile['education']) ?></textarea>

            <label>Email</label>
            <input
                type="email"
                name="email"
                value="<?= h($profile['email']) ?>"
            >

            <label>Phone</label>
            <input name="phone" value="<?= h($profile['phone']) ?>">

            <label>Location</label>
            <input name="location" value="<?= h($profile['location']) ?>">

            <label>Upload Website Logo</label>
            <input type="file" name="logo" accept="image/*">

            <?php if ($profile['logo']): ?>
                <img
                    src="<?= h($profile['logo']) ?>"
                    alt="Current logo"
                    style="width:100px;border-radius:12px"
                >
            <?php endif; ?>

            <label>Upload Profile Photo</label>
            <input type="file" name="profile_photo" accept="image/*">

            <?php if ($profile['profile_photo']): ?>
                <img
                    src="<?= h($profile['profile_photo']) ?>"
                    alt="Current profile"
                    style="width:120px;border-radius:50%"
                >
            <?php endif; ?>

            <button class="btn" type="submit">
                Save Homepage Information
            </button>
        </form>

    <?php elseif ($tab === 'projects'): ?>

        <h2>Project Management</h2>

        <p class="small">
            Confirm your developer password before adding or editing a project.
        </p>

        <form
            class="admin-form neon"
            method="post"
            enctype="multipart/form-data"
            style="padding:20px;margin-bottom:25px"
        >
            <h3>
                <?= $editProject ? 'Edit Project' : 'Add New Project' ?>
            </h3>

            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_project">

            <input
                type="hidden"
                name="id"
                value="<?= (int)($editProject['id'] ?? 0) ?>"
            >

            <label>Confirm Developer Password</label>
            <input
                type="password"
                name="developer_password"
                required
                autocomplete="current-password"
            >

            <label>Project Title</label>
            <input
                name="title"
                required
                value="<?= h($editProject['title'] ?? '') ?>"
            >

            <label>Description</label>
            <textarea name="description" required><?= h($editProject['description'] ?? '') ?></textarea>

            <label>Technologies</label>
            <input
                name="technologies"
                placeholder="PHP, MySQL, HTML, CSS, JavaScript"
                value="<?= h($editProject['technologies'] ?? '') ?>"
            >

            <label>Live Project Link</label>
            <input
                type="url"
                name="project_link"
                value="<?= h($editProject['project_link'] ?? '') ?>"
            >

            <label>GitHub Link</label>
            <input
                type="url"
                name="github_link"
                value="<?= h($editProject['github_link'] ?? '') ?>"
            >

            <label>Project Image</label>
            <input type="file" name="image" accept="image/*">

            <button class="btn" type="submit">
                Save Project
            </button>

            <a class="btn danger" href="?page=dashboard&tab=projects">
                Cancel
            </a>
        </form>

        <div class="table-wrap">
            <table>
                <tr>
                    <th>Project</th>
                    <th>Technologies</th>
                    <th>Actions</th>
                </tr>

                <?php foreach ($projects as $p): ?>
                    <tr>
                        <td><?= h($p['title']) ?></td>
                        <td><?= h($p['technologies']) ?></td>
                        <td>
                            <a
                                class="btn purple"
                                href="?page=dashboard&tab=projects&edit_project=<?= (int)$p['id'] ?>"
                            >
                                Edit
                            </a>

                            <form
                                class="inline-form"
                                method="post"
                                onsubmit="return confirm('Delete this project?')"
                            >
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_project">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">

                                <button class="btn danger" type="submit">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

    <?php elseif ($tab === 'skills'): ?>

        <h2>Manage Skills</h2>

        <form
            class="admin-form neon"
            style="padding:20px;margin-bottom:20px"
            method="post"
        >
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_skill">

            <input
                type="hidden"
                name="id"
                value="<?= (int)($editSkill['id'] ?? 0) ?>"
            >

            <label>Skill Name</label>
            <input
                name="name"
                required
                value="<?= h($editSkill['name'] ?? '') ?>"
            >

            <label>Skill Level (0-100)</label>
            <input
                type="number"
                name="level"
                min="0"
                max="100"
                value="<?= (int)($editSkill['level'] ?? 75) ?>"
            >

            <button class="btn" type="submit">Save Skill</button>

            <a class="btn danger" href="?page=dashboard&tab=skills">
                Cancel
            </a>
        </form>

        <div class="table-wrap">
            <table>
                <tr>
                    <th>Skill</th>
                    <th>Level</th>
                    <th>Actions</th>
                </tr>

                <?php foreach ($skills as $s): ?>
                    <tr>
                        <td><?= h($s['name']) ?></td>
                        <td><?= (int)$s['level'] ?>%</td>
                        <td>
                            <a
                                class="btn purple"
                                href="?page=dashboard&tab=skills&edit_skill=<?= (int)$s['id'] ?>"
                            >
                                Edit
                            </a>

                            <form class="inline-form" method="post">
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_skill">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">

                                <button class="btn danger" type="submit">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

    <?php elseif ($tab === 'services'): ?>

        <h2>Manage Services</h2>

        <form
            class="admin-form neon"
            style="padding:20px;margin-bottom:20px"
            method="post"
        >
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_service">

            <input
                type="hidden"
                name="id"
                value="<?= (int)($editService['id'] ?? 0) ?>"
            >

            <label>Service Title</label>
            <input
                name="title"
                required
                value="<?= h($editService['title'] ?? '') ?>"
            >

            <label>Description</label>
            <textarea name="description"><?= h($editService['description'] ?? '') ?></textarea>

            <button class="btn" type="submit">Save Service</button>

            <a class="btn danger" href="?page=dashboard&tab=services">
                Cancel
            </a>
        </form>

        <div class="table-wrap">
            <table>
                <tr>
                    <th>Service</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr>

                <?php foreach ($services as $s): ?>
                    <tr>
                        <td><?= h($s['title']) ?></td>
                        <td><?= h($s['description']) ?></td>
                        <td>
                            <a
                                class="btn purple"
                                href="?page=dashboard&tab=services&edit_service=<?= (int)$s['id'] ?>"
                            >
                                Edit
                            </a>

                            <form class="inline-form" method="post">
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_service">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">

                                <button class="btn danger" type="submit">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

    <?php elseif ($tab === 'socials'): ?>

        <h2>Manage Social Media Links</h2>

        <p class="small">
            Add Facebook, Telegram, LinkedIn, GitHub, Instagram,
            YouTube or any other social media account.
            Active links automatically appear in the moving social-media section.
        </p>

        <form
            class="admin-form neon"
            style="padding:20px;margin-bottom:20px"
            method="post"
        >
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_social">

            <input
                type="hidden"
                name="id"
                value="<?= (int)($editSocial['id'] ?? 0) ?>"
            >

            <label>Platform Name</label>
            <input
                name="name"
                placeholder="Facebook, Telegram, GitHub..."
                required
                value="<?= h($editSocial['name'] ?? '') ?>"
            >

            <label>Profile URL</label>
            <input
                type="url"
                name="url"
                placeholder="https://..."
                required
                value="<?= h($editSocial['url'] ?? '') ?>"
            >

            <button class="btn" type="submit">
                Save Social Link
            </button>

            <a class="btn danger" href="?page=dashboard&tab=socials">
                Cancel
            </a>
        </form>

        <div class="table-wrap">
            <table>
                <tr>
                    <th>Platform</th>
                    <th>URL</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>

                <?php foreach ($allSocials as $s): ?>
                    <tr>
                        <td><?= h($s['name']) ?></td>
                        <td><?= h($s['url']) ?></td>
                        <td><?= $s['active'] ? 'Active' : 'Hidden' ?></td>

                        <td>
                            <a
                                class="btn purple"
                                href="?page=dashboard&tab=socials&edit_social=<?= (int)$s['id'] ?>"
                            >
                                Edit
                            </a>

                            <form class="inline-form" method="post">
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle_social">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">

                                <button class="btn gold" type="submit">
                                    Toggle
                                </button>
                            </form>

                            <form
                                class="inline-form"
                                method="post"
                                onsubmit="return confirm('Delete this link?')"
                            >
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_social">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">

                                <button class="btn danger" type="submit">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

    <?php elseif ($tab === 'messages'): ?>

        <h2>Contact Messages</h2>

        <div class="table-wrap">
            <table>
                <tr>
                    <th>Sender</th>
                    <th>Email</th>
                    <th>Subject / Message</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>

                <?php foreach ($messages as $m): ?>
                    <tr>
                        <td><?= h($m['sender_name']) ?></td>
                        <td><?= h($m['sender_email']) ?></td>

                        <td>
                            <strong><?= h($m['subject']) ?></strong>
                            <br>
                            <?= nl2br(h($m['message'])) ?>
                        </td>

                        <td>
                            <?= h($m['created_at']) ?>
                            <br>
                            <?= $m['is_read'] ? 'Read' : 'Unread' ?>
                        </td>

                        <td>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="read_message">
                                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">

                                <button class="btn gold" type="submit">
                                    Mark Read
                                </button>
                            </form>

                            <form
                                method="post"
                                onsubmit="return confirm('Delete message?')"
                            >
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_message">
                                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">

                                <button class="btn danger" type="submit">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

    <?php elseif ($tab === 'account'): ?>

        <h2>Change Developer Password</h2>

        <form class="admin-form" method="post">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="change_password">

            <label>Current Password</label>
            <input
                type="password"
                name="current_password"
                required
            >

            <label>New Password (minimum 6 characters)</label>
            <input
                type="password"
                name="new_password"
                minlength="12"
                required
            >

            <label>Confirm New Password</label>
            <input
                type="password"
                name="confirm_password"
                minlength="12"
                required
            >

            <button class="btn" type="submit">
                Update Password
            </button>
        </form>

    <?php endif; ?>

    </main>
</div>

<?php else: ?>

<!-- =====================================================
     PUBLIC PORTFOLIO HOMEPAGE
     ===================================================== -->

<header>
    <div class="container nav">

        <a class="brand" href="?page=home">

            <?php if ($profile['logo']): ?>

                <img
                    src="<?= h($profile['logo']) ?>"
                    alt="Website Logo"
                >

            <?php else: ?>

                <span style="
                    border:2px solid var(--green);
                    padding:8px 12px;
                    border-radius:50%
                ">SB</span>

            <?php endif; ?>

            <span><?= h($profile['full_name']) ?></span>
        </a>

        <nav>
            <a href="#home" data-i18n="home">Home</a>
            <a href="#about" data-i18n="about">About</a>
            <a href="#skills" data-i18n="skills">Skills</a>
            <a href="#projects" data-i18n="projects">Projects</a>
            <a href="#services" data-i18n="services">Services</a>
            <a href="#contact" data-i18n="contact">Contact</a>

            <select
                id="languageSelect"
                class="language-select"
                aria-label="Select language"
            >
                <option value="en">English</option>
                <option value="am">አማርኛ</option>
            </select>
        </nav>
    </div>
</header>

<!-- HERO -->

<section class="hero" id="home">
    <div class="container hero-grid">

        <div>
            <div class="kicker" data-i18n="welcome">
                WELCOME TO MY PORTFOLIO
            </div>

            <h1>
                <span data-i18n="hello">Hello, I'm</span>
                <br>
                <span><?= h($profile['full_name']) ?></span>
            </h1>

            <div class="job">
                <span id="typing">
                    <?= h($profile['job_title']) ?>
                </span>
            </div>

            <p><?= nl2br(h($profile['introduction'])) ?></p>

            <div class="actions">
                <a class="btn" href="#projects" data-i18n="exploreProjects">
                    Explore My Projects
                </a>

                <a class="btn gold" href="#contact" data-i18n="contactButton">
                    Contact Me
                </a>

                <a class="btn purple" href="?page=login" data-i18n="S">
                  S
                </a>
            </div>
        </div>

        <div>
            <?php if ($profile['profile_photo']): ?>

                <img
                    class="hero-photo"
                    src="<?= h($profile['profile_photo']) ?>"
                    alt="Profile photo"
                >

            <?php else: ?>

                <div class="hero-photo placeholder">SB</div>

            <?php endif; ?>
        </div>

    </div>
</section>

<!-- MOVING SKILLS STRIP -->

<div class="marquee-wrap">
    <div class="marquee">

        <?php for ($i = 0; $i < 2; $i++): ?>
            <?php foreach ($skills as $s): ?>

                <span>
                    <?= h($s['name']) ?> ✦
                </span>

            <?php endforeach; ?>
        <?php endfor; ?>

    </div>
</div>

<!-- ABOUT -->

<section id="about">
    <div class="container">

        <div class="section-title">
            <h2 data-i18n="aboutMe">About Me</h2>
            <p data-i18n="background">
                My background and professional journey
            </p>
        </div>

        <div class="card neon">
            <h3><?= h($profile['full_name']) ?></h3>

            <p><?= nl2br(h($profile['about'])) ?></p>

            <br>

            <h3 data-i18n="education">Education</h3>

            <p><?= nl2br(h($profile['education'])) ?></p>
        </div>

    </div>
</section>

<!-- SKILLS -->


<div class="skills-grid">

    <?php
    $skillIcons = [
        'HTML5' => 'fab fa-html5',
        'CSS3' => 'fab fa-css3-alt',
        'JavaScript' => 'fab fa-js',
        'PHP' => 'fab fa-php',
        'MySQL' => 'fas fa-database',
        'Python' => 'fab fa-python',
        'C++' => 'fas fa-code',
        'Java' => 'fab fa-java',
        'UI/UX Design' => 'fas fa-pen-nib',
        'Android Development' => 'fab fa-android',
        'Git & GitHub' => 'fab fa-github'
    ];

    foreach ($skills as $skill):
        $skillName = is_array($skill) ? $skill['name'] : $skill;
        $icon = $skillIcons[$skillName] ?? 'fas fa-code';
    ?>

        <div class="skill-card">
            <div class="skill-icon">
                <i class="<?= h($icon) ?>"></i>
            </div>

            <h3><?= h($skillName) ?></h3>

            <span>TECHNOLOGY</span>
        </div>

    <?php endforeach; ?>

</div>

<!-- MOVING SERVICES STRIP -->

<div class="marquee-wrap">
    <div class="marquee">

        <?php for ($i = 0; $i < 2; $i++): ?>
            <?php foreach ($services as $s): ?>

                <span>
                    <?= h($s['title']) ?> ✦
                </span>

            <?php endforeach; ?>
        <?php endfor; ?>

    </div>
</div>

<!-- PROJECTS -->


<section id="projects">
    <div class="container">

        <div class="section-title">
            <h2 data-i18n="myProjects">My Projects</h2>
            <p data-i18n="projectsDescription">
                Selected work and development projects
            </p>
        </div>

        <div class="grid">

            <?php foreach ($projects as $p): ?>

                <article class="card neon project-card">

                    <?php if (!empty($p['image'])): ?>

                        <img
                            class="project-image"
                            src="<?= h($p['image']) ?>"
                            alt="<?= h($p['title'] ?? 'Project image') ?>"
                        >

                    <?php else: ?>

                        <div class="project-placeholder">
                            💻
                        </div>

                    <?php endif; ?>

                    <h3>
                        <?= h($p['title'] ?? 'My Project') ?>
                    </h3>

                    <p>
                        <?= h($p['description'] ?? '') ?>
                    </p>

                    <div class="project-buttons">

                        <!-- Live Project button -->
                        <a
                            class="btn project-btn"
                            href="https://github.com/beyenesintayehu4-stack"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <i class="fas fa-external-link-alt"></i>
                            View Project
                        </a>

                        <!-- GitHub Profile button -->
                        <a
                            class="btn purple project-btn"
                            href="https://github.com/beyenesintayehu4-stack"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <i class="fab fa-github"></i>
                            GitHub
                        </a>

                    </div>

                </article>

            <?php endforeach; ?>

            <?php if (empty($projects)): ?>

                <div class="card neon">
                    <h3>Projects Coming Soon</h3>
                    <p>New projects will be displayed here.</p>
                </div>

            <?php endif; ?>

        </div>

        <div style="text-align:center;margin-top:30px">
            <a class="btn gold" href="?page=login">
                Add New Project – 
            </a>
        </div>

    </div>
</section>


<!-- SERVICES -->

<section id="services">
    <div class="container">

        <div class="section-title">
            <h2 data-i18n="myServices">My Services</h2>
            <p data-i18n="servicesDescription">
                Professional development services
            </p>
        </div>

        <div class="grid">

            <?php foreach ($services as $s): ?>

                <div class="card neon">
                    <h3><?= h($s['title']) ?></h3>
                    <p><?= nl2br(h($s['description'])) ?></p>
                </div>

            <?php endforeach; ?>

        </div>
    </div>
</section>


<!-- CONTACT -->

<section id="contact">
    <div class="container">

        <div class="section-title">
            <h2 data-i18n="contactMe">Contact Me</h2>
            <p data-i18n="contactDescription">
                Let's discuss your next project
            </p>
        </div>

        <div
            class="card neon"
            style="max-width:720px;margin:auto"
        >

            <form method="post">

                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="contact">

                <label data-i18n="yourName">Your Name</label>
                <input name="sender_name" required>

                <label data-i18n="yourEmail">Your Email</label>
                <input type="email" name="sender_email" required>

                <label data-i18n="subject">Subject</label>
                <input name="subject" required>

                <label data-i18n="message">Message</label>
                <textarea name="message" required></textarea>

                <button class="btn" type="submit" data-i18n="sendMessage">
                    Send Message
                </button>

            </form>

        </div>

        <div style="
            text-align:center;
            margin-top:25px;
            color:var(--muted)
        ">
            <?= h($profile['email']) ?>

            <?= $profile['phone']
                ? ' | ' . h($profile['phone'])
                : '' ?>

            <?= $profile['location']
                ? ' | ' . h($profile['location'])
                : '' ?>
        </div>

    </div>
</section>

<!-- MOVING SOCIAL MEDIA LINKS -->

<div class="social-marquee-wrap">

    <?php if ($socials): ?>

        <div class="social-marquee">

            <?php for ($repeat = 2; $repeat < 4; $repeat++): ?>

                <?php foreach ($socials as $s): ?>

                    <a
                        class="social-chip"
                        href="<?= h($s['url']) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                    >

                        <span class="social-icon">
                            <?= h(strtoupper(substr($s['name'], 0, 2))) ?>
                        </span>

                        <?= h($s['name']) ?>

                        <span>✦</span>

                    </a>

                <?php endforeach; ?>

            <?php endfor; ?>

        </div>

    <?php else: ?>

        <div style="text-align:center;color:var(--muted)">
            Add your social media links from the developer dashboard.
        </div>

    <?php endif; ?>

</div>

<!-- FOOTER -->


<footer>
    <div class="container footer-inner">

        <div>
            <h3 style="color:var(--gold)">
                <?= h($profile['full_name']) ?>
            </h3>

            <p class="small">
                © <?= date('Y') ?> All rights reserved.
            </p>

            <a class="small" href="?page=login" data-i18n="s">
                 s
            </a>
        </div>

        <div class="socials">

            <?php foreach ($socials as $s): ?>

                <a
                    target="_blank"
                    rel="noopener noreferrer"
                    href="<?= h($s['url']) ?>"
                >
                    <?= h($s['name']) ?>
                </a>

            <?php endforeach; ?>

        </div>

    </div>
</footer>

<?php endif; ?>

<script>

/* =========================================================
   ANIMATED PARTICLE BACKGROUND
   ========================================================= */

(() => {
    const canvas = document.getElementById('particles');

    if (!canvas) return;

    const ctx = canvas.getContext('2d');

    let dots = [];

    function resize() {
        const ratio = window.devicePixelRatio || 1;

        canvas.width = window.innerWidth * ratio;
        canvas.height = window.innerHeight * ratio;

        canvas.style.width = window.innerWidth + 'px';
        canvas.style.height = window.innerHeight + 'px';

        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

        dots = Array.from({length:65}, () => ({
            x: Math.random() * window.innerWidth,
            y: Math.random() * window.innerHeight,
            r: Math.random() * 2 + .5,
            vx: (Math.random() - .5) * .35,
            vy: (Math.random() - .5) * .35
        }));
    }

    function draw() {
        ctx.clearRect(
            0,
            0,
            window.innerWidth,
            window.innerHeight
        );

        for (let i = 0; i < dots.length; i++) {
            const d = dots[i];

            d.x += d.vx;
            d.y += d.vy;

            if (d.x < 0 || d.x > window.innerWidth) d.vx *= -1;
            if (d.y < 0 || d.y > window.innerHeight) d.vy *= -1;

            ctx.beginPath();
            ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);

            ctx.fillStyle = i % 2
                ? 'rgba(57,255,136,.65)'
                : 'rgba(179,76,255,.7)';

            ctx.fill();

            for (let j = i + 1; j < dots.length; j++) {
                const e = dots[j];

                const dist = Math.hypot(
                    d.x - e.x,
                    d.y - e.y
                );

                if (dist < 115) {
                    ctx.beginPath();
                    ctx.moveTo(d.x, d.y);
                    ctx.lineTo(e.x, e.y);

                    ctx.strokeStyle =
                        'rgba(179,76,255,' +
                        (1 - dist / 115) * .15 +
                        ')';

                    ctx.stroke();
                }
            }
        }

        requestAnimationFrame(draw);
    }

    window.addEventListener('resize', resize);

    resize();
    draw();
})();

/* =========================================================
   ANIMATED PROFESSIONAL TITLE
   ========================================================= */

(() => {
    const el = document.getElementById('typing');

    if (!el) return;

    const words = [
        'my name is sintayehu i study Computer Science and Engineering - (CSE)',
        'Full-Stack Developer',
        'website Developer',
        'Frontend Developer',
        'Backend Developer',
        'Web Application Developer'
    ];

    let word = 0;
    let char = 0;
    let deleting = false;

    function type() {
        const current = words[word];

        el.textContent = current.substring(0, char);

        if (!deleting) {
            char++;

            if (char > current.length) {
                deleting = true;
                setTimeout(type, 1300);
                return;
            }
        } else {
            char--;

            if (char < 0) {
                char = 0;
                deleting = false;
                word = (word + 1) % words.length;
            }
        }

        setTimeout(type, deleting ? 45 : 85);
    }

    type();
})();

/* =========================================================
   ENGLISH / AMHARIC LANGUAGE SWITCHER
   ========================================================= */

(() => {
    const selector = document.getElementById('languageSelect');

    if (!selector) return;

    const translations = {
        en: {
            home: 'Home',
            about: 'About',
            skills: 'Skills',
            projects: 'Projects',
            services: 'Services',
            contact: 'Contact',
            welcome: 'WELCOME TO MY PORTFOLIO',
            hello: "Hello, I'm",
            exploreProjects: 'Explore My Projects',
            contactButton: 'Contact Me',
            developerLogin: 'Developer Login',
            aboutMe: 'About Me',
            background: 'My background and professional journey',
            education: 'Education',
            mySkills: 'My Skills',
            skillsDescription: 'Technologies and tools I work with',
            myProjects: 'My Projects',
            projectsDescription: 'Selected work and development projects',
            myServices: 'My Services',
            servicesDescription: 'Professional development services',
            contactMe: 'Contact Me',
            contactDescription: "Let's discuss your next project",
            yourName: 'Your Name',
            yourEmail: 'Your Email',
            subject: 'Subject',
            message: 'Message',
            sendMessage: 'Send Message'
        },

        am: {
            home: 'መነሻ',
            about: 'ስለ እኔ',
            skills: 'ክህሎቶች',
            projects: 'ፕሮጀክቶች',
            services: 'አገልግሎቶች',
            contact: 'አግኙኝ',
            welcome: 'ወደ የእኔ ፖርትፎሊዮ እንኳን በደህና መጡ',
            hello: 'ሰላም፣ እኔ',
            exploreProjects: 'ፕሮጀክቶቼን ይመልከቱ',
            contactButton: 'አግኙኝ',
            developerLogin: 'የዴቨሎፐር መግቢያ',
            aboutMe: 'ስለ እኔ',
            background: 'ስለ ትምህርቴና የሙያ ጉዞዬ',
            education: 'ትምህርት',
            mySkills: 'የእኔ ክህሎቶች',
            skillsDescription: 'የምጠቀምባቸው ቴክኖሎጂዎችና መሳሪያዎች',
            myProjects: 'የእኔ ፕሮጀክቶች',
            projectsDescription: 'የሰራኋቸው ፕሮጀክቶች',
            myServices: 'የእኔ አገልግሎቶች',
            servicesDescription: 'የሶፍትዌር እና የድረ-ገጽ ልማት አገልግሎቶች',
            contactMe: 'አግኙኝ',
            contactDescription: 'ስለ ፕሮጀክትዎ እንወያይ',
            yourName: 'ስምዎ',
            yourEmail: 'ኢሜይልዎ',
            subject: 'ርዕስ',
            message: 'መልዕክት',
            sendMessage: 'መልዕክት ላክ'
        }
    };

    function applyLanguage(language) {
        const selected = translations[language]
            ? language
            : 'en';

        document.documentElement.lang = selected;

        document.querySelectorAll('[data-i18n]').forEach(element => {
            const key = element.dataset.i18n;

            if (translations[selected][key]) {
                element.textContent = translations[selected][key];
            }
        });

        selector.value = selected;

        try {
            localStorage.setItem('portfolioLanguage', selected);
        } catch (error) {
            // Language still works for this page if storage is unavailable.
        }
    }

    selector.addEventListener('change', () => {
        applyLanguage(selector.value);
    });

    let savedLanguage = 'en';

    try {
        savedLanguage =
            localStorage.getItem('portfolioLanguage') || 'en';
    } catch (error) {
        savedLanguage = 'en';
    }

    applyLanguage(savedLanguage);
})();

</script>

</body>
</html>
