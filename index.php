<?php
session_start();

// Admin password - Change this to your desired password
define('ADMIN_PASSWORD', 'IloveFusion@1234');

// Default quiz data structure
$defaultQuizData = [
    'categories' => [
        [
            'id' => 1,
            'name' => 'Sales & Presales',
            'color' => 'sales',
            'quizzes' => [
                ['name' => 'Sales', 'url' => 'https://forms.office.com/Pages/ResponsePage.aspx?id=UHUeFoymt0mpL23ehzK4R4tO3JaI8U1NmhZR5pRDGLdUQU81SjFCUVBES1JWNkZBWjVZN0JOUVVCMi4u'],
                ['name' => 'Presale Engineer', 'url' => 'https://forms.office.com/r/RV8LNMjXtK'],
                ['name' => 'Presale Manager', 'url' => 'https://forms.office.com/r/Ea1vD11m7m']
            ]
        ],
        [
            'id' => 2,
            'name' => 'Support',
            'color' => 'engineering',
            'quizzes' => [
                ['name' => 'Support Engineer', 'url' => 'https://forms.office.com/r/QKHntJpv90']
            ]
        ],
        [
            'id' => 3,
            'name' => 'Technical',
            'color' => 'technical',
            'quizzes' => [
                ['name' => 'Project Management', 'url' => 'https://forms.office.com/r/4tbckR4Avg'],
                ['name' => 'Technical (Security)', 'url' => 'https://forms.office.com/r/ANd43iNHXG'],
                ['name' => 'Network', 'url' => 'https://forms.office.com/r/7r0eDvXXPG'],
                ['name' => 'NodeJS', 'url' => 'https://forms.office.com/r/Mre9tBtg8N'],
                ['name' => 'Mobile DevOps', 'url' => 'https://forms.office.com/r/E67smShEut']
            ]
        ],
        [
            'id' => 4,
            'name' => 'Personal & English',
            'color' => 'personal',
            'quizzes' => [
                ['name' => 'Personal', 'url' => 'https://forms.office.com/r/tUCpmKYWYa'],
                ['name' => 'English', 'url' => 'https://forms.office.com/r/X7zY1Mmq5N'],
                ['name' => 'English for Manager', 'url' => 'https://forms.office.com/r/2Rpx4wsfDR'],
                ['name' => 'External English Test', 'url' => 'https://preply.com/en/language-tests/english']
            ]
        ]
    ]
];

// Initialize quiz data file if it doesn't exist
$quizDataFile = 'quiz_data.json';
if (!file_exists($quizDataFile)) {
    file_put_contents($quizDataFile, json_encode($defaultQuizData, JSON_PRETTY_PRINT));
}

// Load quiz data
function loadQuizData() {
    global $quizDataFile, $defaultQuizData;
    $data = @file_get_contents($quizDataFile);
    return $data ? json_decode($data, true) : $defaultQuizData;
}

function listCaptureFiles($uploadDir) {
    $files = [];
    foreach (glob($uploadDir . '*.{png,jpg,jpeg,PNG,JPG,JPEG}', GLOB_BRACE) ?: [] as $filepath) {
        if (is_file($filepath)) {
            $files[] = $filepath;
        }
    }
    return $files;
}

function captureFilePath($uploadDir, $filename) {
    $base = basename((string)$filename);
    if (!preg_match('/\.(png|jpe?g)$/i', $base)) {
        return null;
    }
    $filepath = $uploadDir . $base;
    return is_file($filepath) ? $filepath : null;
}

// Save quiz data
function saveQuizData($data) {
    global $quizDataFile;
    file_put_contents($quizDataFile, json_encode($data, JSON_PRETTY_PRINT));
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $uploadDir = 'captures/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    switch ($_POST['action']) {
        case 'verify_admin_password':
            $password = $_POST['password'] ?? '';
            if ($password === ADMIN_PASSWORD) {
                $_SESSION['admin_authenticated'] = true;
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid password']);
            }
            break;

        case 'admin_session_status':
            echo json_encode(['success' => true, 'authenticated' => !empty($_SESSION['admin_authenticated'])]);
            break;
            
        case 'save_image':
            $imageData = $_POST['image'];
            $type = $_POST['type'] ?? 'start';
            $userId = $_POST['user_id'] ?? 'user_' . time();
            
            $imageData = str_replace('data:image/png;base64,', '', $imageData);
            $imageData = str_replace(' ', '+', $imageData);
            $decodedImage = base64_decode($imageData);
            
            $filename = $uploadDir . $userId . '_' . $type . '_' . date('Y-m-d_H-i-s') . '.png';
            
            if (file_put_contents($filename, $decodedImage)) {
                echo json_encode(['success' => true, 'filename' => $filename]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save image']);
            }
            break;
            
        case 'load_quiz_data':
            echo json_encode(['success' => true, 'data' => loadQuizData()]);
            break;
            
        case 'add_category':
            $data = loadQuizData();
            $maxId = 0;
            foreach ($data['categories'] as $cat) {
                if ($cat['id'] > $maxId) $maxId = $cat['id'];
            }
            $data['categories'][] = [
                'id' => $maxId + 1,
                'name' => $_POST['name'],
                'color' => $_POST['color'],
                'quizzes' => []
            ];
            saveQuizData($data);
            echo json_encode(['success' => true, 'data' => $data]);
            break;
            
        case 'edit_category':
            $data = loadQuizData();
            $categoryId = intval($_POST['category_id']);
            foreach ($data['categories'] as &$cat) {
                if ($cat['id'] == $categoryId) {
                    $cat['name'] = $_POST['name'];
                    $cat['color'] = $_POST['color'];
                    break;
                }
            }
            saveQuizData($data);
            echo json_encode(['success' => true, 'data' => $data]);
            break;
            
        case 'move_category_up':
            $data = loadQuizData();
            $categoryId = intval($_POST['category_id'] ?? 0);
            $index = null;
            foreach ($data['categories'] as $i => $cat) {
                if ($cat['id'] == $categoryId) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                echo json_encode(['success' => false, 'error' => 'Category not found']);
                break;
            }
            if ($index === 0) {
                echo json_encode(['success' => false, 'error' => 'top']);
                break;
            }
            $previous = $data['categories'][$index - 1];
            $data['categories'][$index - 1] = $data['categories'][$index];
            $data['categories'][$index] = $previous;
            saveQuizData($data);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'delete_category':
            $data = loadQuizData();
            $categoryId = intval($_POST['category_id']);
            $data['categories'] = array_values(array_filter($data['categories'], function($cat) use ($categoryId) {
                return $cat['id'] != $categoryId;
            }));
            saveQuizData($data);
            echo json_encode(['success' => true, 'data' => $data]);
            break;
            
        case 'add_quiz':
            $data = loadQuizData();
            $categoryId = intval($_POST['category_id']);
            foreach ($data['categories'] as &$cat) {
                if ($cat['id'] == $categoryId) {
                    $cat['quizzes'][] = [
                        'name' => $_POST['name'],
                        'url' => $_POST['url']
                    ];
                    break;
                }
            }
            saveQuizData($data);
            echo json_encode(['success' => true, 'data' => $data]);
            break;
            
        case 'edit_quiz':
            $data = loadQuizData();
            $categoryId = intval($_POST['category_id']);
            $quizIndex = intval($_POST['quiz_index']);
            foreach ($data['categories'] as &$cat) {
                if ($cat['id'] == $categoryId) {
                    if (isset($cat['quizzes'][$quizIndex])) {
                        $cat['quizzes'][$quizIndex]['name'] = $_POST['name'];
                        $cat['quizzes'][$quizIndex]['url'] = $_POST['url'];
                    }
                    break;
                }
            }
            saveQuizData($data);
            echo json_encode(['success' => true, 'data' => $data]);
            break;
            
        case 'delete_quiz':
            $data = loadQuizData();
            $categoryId = intval($_POST['category_id']);
            $quizIndex = intval($_POST['quiz_index']);
            foreach ($data['categories'] as &$cat) {
                if ($cat['id'] == $categoryId) {
                    array_splice($cat['quizzes'], $quizIndex, 1);
                    break;
                }
            }
            saveQuizData($data);
            echo json_encode(['success' => true, 'data' => $data]);
            break;
            
        case 'get_captures':
            $images = [];
            if (is_dir($uploadDir)) {
                $files = scandir($uploadDir);
                foreach ($files as $file) {
                    if (preg_match('/\.(png|jpg|jpeg)$/i', $file)) {
                        $images[] = [
                            'name' => $file,
                            'path' => $uploadDir . $file,
                            'size' => filesize($uploadDir . $file),
                            'date' => date('Y-m-d H:i:s', filemtime($uploadDir . $file))
                        ];
                    }
                }
            }
            echo json_encode(['success' => true, 'images' => $images]);
            break;
            
        case 'delete_image':
            if (empty($_SESSION['admin_authenticated'])) {
                echo json_encode(['success' => false, 'error' => 'Admin login required']);
                break;
            }
            $filepath = captureFilePath($uploadDir, $_POST['filename'] ?? '');
            if ($filepath && unlink($filepath)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'File not found']);
            }
            break;

        case 'delete_captures':
            if (empty($_SESSION['admin_authenticated'])) {
                echo json_encode(['success' => false, 'error' => 'Admin login required']);
                break;
            }
            $filenames = $_POST['filenames'] ?? [];
            if (!is_array($filenames) || count($filenames) === 0) {
                echo json_encode(['success' => false, 'error' => 'No captures selected']);
                break;
            }
            $deleted = 0;
            foreach ($filenames as $filename) {
                $filepath = captureFilePath($uploadDir, $filename);
                if ($filepath && unlink($filepath)) {
                    $deleted++;
                }
            }
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            break;

        case 'delete_all_captures':
            if (empty($_SESSION['admin_authenticated'])) {
                echo json_encode(['success' => false, 'error' => 'Admin login required']);
                break;
            }
            $deleted = 0;
            foreach (listCaptureFiles($uploadDir) as $filepath) {
                if (unlink($filepath)) {
                    $deleted++;
                }
            }
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            break;
    }
    exit;
}

$quizData = loadQuizData();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fusion Advantec - Quiz Proctoring System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .container {
            display: grid;
            grid-template-columns: 350px 1fr;
            height: 100vh;
            gap: 0;
        }

        .sidebar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }

        .webcam-section {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            padding: 20px;
            text-align: center;
        }

        #webcamVideo {
            width: 100%;
            height: 200px;
            border-radius: 15px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            object-fit: cover;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        .webcam-status {
            margin-top: 10px;
            padding: 8px 15px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            color: white;
            font-size: 12px;
            display: inline-block;
        }

        .admin-btn-container {
            padding: 15px 20px;
            background: rgba(0, 0, 0, 0.05);
        }

        .admin-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }

        .admin-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.3);
        }

        .rules-section {
            padding: 25px;
            flex: 1;
            overflow-y: auto;
        }

        h2 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rules-list {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            color: white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .rules-list ol {
            margin-left: 20px;
        }

        .rules-list li {
            margin: 10px 0;
            line-height: 1.6;
        }

        .quiz-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .quiz-category {
            margin-bottom: 20px;
        }

        .category-title {
            font-size: 14px;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 10px;
            font-weight: 600;
            letter-spacing: 1px;
        }

        .quiz-btn {
            width: 100%;
            padding: 15px 20px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            margin-bottom: 8px;
        }

        .quiz-btn:before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .quiz-btn:hover:before {
            width: 300px;
            height: 300px;
        }

        .quiz-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }

        .quiz-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .quiz-btn.sales { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .quiz-btn.technical { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .quiz-btn.personal { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .quiz-btn.engineering { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .quiz-btn.dev { background: linear-gradient(135deg, #30cfd0 0%, #330867 100%); }
        .quiz-btn.english { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333; }

        .quiz-frame {
            background: white;
            border-radius: 0;
            box-shadow: -4px 0 20px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        iframe {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 0;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            overflow-y: auto;
            padding: 20px;
        }

        .modal-content {
            background: white;
            max-width: 1000px;
            margin: 20px auto;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eee;
        }

        .modal-title {
            font-size: 28px;
            color: #667eea;
            font-weight: 700;
        }

        .close-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: #ff4757;
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .close-btn:hover {
            background: #ff3838;
            transform: rotate(90deg);
        }

        .admin-section {
            margin-bottom: 40px;
        }

        .admin-section h3 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 5px;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff4757 0%, #ff6348 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #feca57 0%, #ff9ff3 100%);
            color: #333;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .category-item, .quiz-item {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }

        .category-item {
            border-left-color: #f093fb;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .item-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .item-actions {
            display: flex;
            gap: 8px;
        }

        input[type="text"], input[type="url"], select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 15px;
            transition: border 0.3s ease;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .quiz-list {
            margin-top: 15px;
            padding-left: 20px;
        }

        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .image-card {
            position: relative;
            background: #f8f9fa;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .capture-check {
            position: absolute;
            top: 8px;
            left: 8px;
            z-index: 2;
            width: 22px;
            height: 22px;
            cursor: pointer;
        }

        .image-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }

        .image-card img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }

        .image-info {
            padding: 15px;
        }

        .image-name {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            word-break: break-all;
        }

        .image-date {
            font-size: 11px;
            color: #999;
            margin-bottom: 10px;
        }

        canvas {
            display: none;
        }

        .alert-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10001;
        }

        .alert-box {
            background: white;
            padding: 30px;
            border-radius: 20px;
            max-width: 500px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .alert-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }

        .alert-title {
            font-size: 24px;
            color: #333;
            margin-bottom: 10px;
        }

        .alert-message {
            font-size: 16px;
            color: #666;
            margin-bottom: 20px;
            line-height: 1.6;
            white-space: pre-line;
        }

        .alert-btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transition: all 0.3s ease;
        }

        .alert-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
                grid-template-rows: auto 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .image-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <div class="webcam-section">
                <video id="webcamVideo" autoplay playsinline></video>
                <div class="webcam-status" id="webcamStatus">📷 Initializing camera...</div>
                <canvas id="canvas"></canvas>
            </div>
            
            <div class="admin-btn-container">
                <button class="admin-btn" onclick="openAdminPanel()">🔧 Admin Panel</button>
                <button class="admin-btn" onclick="exitQuiz()" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); margin-top: 10px;">🚪 Exit</button>
            </div>
            
            <div class="rules-section">
                <h2>📋 Quiz Rules</h2>
                <div class="rules-list">
                    <ol>
                        <li>Click "Allow" to open camera</li>
                        <li>Finish Quiz on time</li>
                        <li>Not allowed to use internet or ChatGPT to find solutions</li>
                        <li>Webcam will be monitored for cheating detection</li>
                    </ol>
                </div>

                <h2>📝 Select Quiz</h2>
                <div id="quizContainer"></div>
            </div>
        </div>

        <div class="quiz-frame">
            <iframe id="quizFrame" src="https://www.fusion.co.th"></iframe>
        </div>
    </div>

    <!-- Admin Modal -->
    <div class="modal" id="adminModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">🔧 Admin Panel</h2>
                <button class="close-btn" onclick="closeAdminPanel()">×</button>
            </div>

            <!-- Categories Management -->
            <div class="admin-section">
                <h3>📁 Manage Categories</h3>
                <button class="btn btn-success" onclick="showAddCategoryForm()">+ Add New Category</button>
                
                <div id="addCategoryForm" style="display:none; margin-top: 20px; background: #f0f0f0; padding: 20px; border-radius: 10px;">
                    <h4>Add New Category</h4>
                    <input type="text" id="newCategoryName" placeholder="Category Name">
                    <select id="newCategoryColor">
                        <option value="sales">Pink/Red</option>
                        <option value="technical">Blue</option>
                        <option value="personal">Green</option>
                        <option value="engineering">Pink/Yellow</option>
                        <option value="dev">Teal/Purple</option>
                        <option value="english">Light Blue/Pink</option>
                    </select>
                    <button class="btn btn-primary" onclick="addCategory()">Save Category</button>
                    <button class="btn btn-danger" onclick="hideAddCategoryForm()">Cancel</button>
                </div>
                
                <div id="categoriesList"></div>
            </div>

            <!-- Captures Viewer -->
            <div class="admin-section">
                <h3>📸 View Captures</h3>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="loadCaptures()">Load Captures</button>
                    <button class="btn btn-primary" onclick="setAllCapturesSelected(true)">Select All</button>
                    <button class="btn btn-primary" onclick="setAllCapturesSelected(false)">Deselect All</button>
                    <button class="btn btn-danger" onclick="deleteSelectedCaptures()">Delete</button>
                    <button class="btn btn-danger" onclick="deleteAllCaptures()">Delete All</button>
                </div>
                <div id="capturesGrid" class="image-grid"></div>
            </div>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal" id="editCategoryModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2 class="modal-title">✏️ Edit Category</h2>
                <button class="close-btn" onclick="closeEditCategoryModal()">×</button>
            </div>
            <input type="hidden" id="editCategoryId">
            <input type="text" id="editCategoryName" placeholder="Category Name">
            <select id="editCategoryColor">
                <option value="sales">Pink/Red</option>
                <option value="technical">Blue</option>
                <option value="personal">Green</option>
                <option value="engineering">Pink/Yellow</option>
                <option value="dev">Teal/Purple</option>
                <option value="english">Light Blue/Pink</option>
            </select>
            <button class="btn btn-primary" onclick="saveEditCategory()">Save Changes</button>
            <button class="btn btn-danger" onclick="closeEditCategoryModal()">Cancel</button>
        </div>
    </div>

    <!-- Add Quiz Modal -->
    <div class="modal" id="addQuizModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2 class="modal-title">➕ Add Quiz</h2>
                <button class="close-btn" onclick="closeAddQuizModal()">×</button>
            </div>
            <input type="hidden" id="addQuizCategoryId">
            <input type="text" id="addQuizName" placeholder="Quiz Name">
            <input type="url" id="addQuizUrl" placeholder="Quiz URL">
            <button class="btn btn-primary" onclick="saveAddQuiz()">Add Quiz</button>
            <button class="btn btn-danger" onclick="closeAddQuizModal()">Cancel</button>
        </div>
    </div>

    <!-- Edit Quiz Modal -->
    <div class="modal" id="editQuizModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2 class="modal-title">✏️ Edit Quiz</h2>
                <button class="close-btn" onclick="closeEditQuizModal()">×</button>
            </div>
            <input type="hidden" id="editQuizCategoryId">
            <input type="hidden" id="editQuizIndex">
            <input type="text" id="editQuizName" placeholder="Quiz Name">
            <input type="url" id="editQuizUrl" placeholder="Quiz URL">
            <button class="btn btn-primary" onclick="saveEditQuiz()">Save Changes</button>
            <button class="btn btn-danger" onclick="closeEditQuizModal()">Cancel</button>
        </div>
    </div>

    <!-- Alert Modal -->
    <div class="alert-overlay" id="alertOverlay">
        <div class="alert-box">
            <div class="alert-icon" id="alertIcon">⚠️</div>
            <div class="alert-title" id="alertTitle">Alert</div>
            <div class="alert-message" id="alertMessage">Message</div>
            <button class="alert-btn" onclick="closeAlert()">OK</button>
        </div>
    </div>

    <!-- PDPA Consent Modal -->
    <div class="modal" id="pdpaModal" style="display: flex;">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2 class="modal-title">🔒 Privacy & Data Protection Notice</h2>
            </div>
            
            <div style="padding: 20px; background: #f8f9fa; border-radius: 10px; margin-bottom: 20px;">
                <h3 style="color: #667eea; margin-bottom: 15px;">Personal Data Protection Act (PDPA) Consent</h3>
                <p style="margin-bottom: 15px; line-height: 1.8; color: #333;">
                    We respect your privacy and are committed to protecting your personal data. By using this quiz proctoring system, you consent to the collection and processing of the following information:
                </p>
                <ul style="margin-left: 30px; margin-bottom: 15px; line-height: 1.8; color: #555;">
                    <li>Photographs of the person taking the quiz, captured from the webcam during the quiz session</li>
                    <li>Quiz participation records and timestamps</li>
                    <li>System-generated user identifiers</li>
                    <li>Activity monitoring data for academic integrity purposes</li>
                </ul>
                <p style="margin-bottom: 15px; line-height: 1.8; color: #333;">
                    <strong>Purpose:</strong> The collected data will be used solely for monitoring quiz integrity, preventing cheating, and maintaining academic standards.
                </p>
                <p style="margin-bottom: 15px; line-height: 1.8; color: #333;">
                    <strong>Data Retention:</strong> Your data will be stored securely and retained only as long as necessary for the stated purposes.
                </p>
                <p style="line-height: 1.8; color: #333;">
                    <strong>Your Rights:</strong> You have the right to access, correct, or request deletion of your personal data in accordance with applicable data protection laws.
                </p>
            </div>
            
            <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin-bottom: 20px;">
                <strong>⚠️ Important:</strong> By clicking "I Accept", you acknowledge that you have read and understood this notice and consent to the collection and use of your personal data as described above.
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: center;">
                <button class="btn btn-success" style="padding: 15px 40px; font-size: 16px;" onclick="acceptPDPA()">✓ I Accept</button>
                <button class="btn btn-danger" style="padding: 15px 40px; font-size: 16px;" onclick="declinePDPA()">✗ I Decline</button>
            </div>
        </div>
    </div>

    <!-- Admin Password Modal -->
    <div class="modal" id="adminPasswordModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title">🔐 Admin Authentication</h2>
                <button class="close-btn" onclick="closeAdminPasswordModal()">×</button>
            </div>
            
            <div style="padding: 20px 0;">
                <p style="margin-bottom: 20px; color: #666; text-align: center;">Please enter the admin password to access the admin panel.</p>
                <input type="password" id="adminPasswordInput" placeholder="Enter admin password" style="text-align: center; font-size: 16px;" onkeypress="if(event.key==='Enter') verifyAdminPassword()">
                <div id="passwordError" style="color: #ff4757; margin-top: 10px; text-align: center; display: none;">❌ Invalid password. Please try again.</div>
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: center;">
                <button class="btn btn-primary" style="padding: 12px 30px;" onclick="verifyAdminPassword()">Submit</button>
                <button class="btn btn-danger" style="padding: 12px 30px;" onclick="closeAdminPasswordModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        let stream = null;
        let userId = 'user_' + Date.now();
        let isQuizActive = false;
        let faceDetectionInterval = null;
        let noFaceWarningCount = 0;
        let quizData = null;
        let pdpaAccepted = false;

        // Check PDPA consent on load
        window.addEventListener('load', () => {
            const consent = sessionStorage.getItem('pdpa_consent');
            if (consent === 'accepted') {
                pdpaAccepted = true;
                document.getElementById('pdpaModal').style.display = 'none';
                initWebcam();
                loadQuizData();
            } else {
                document.getElementById('pdpaModal').style.display = 'flex';
            }
        });

        // PDPA consent functions
        function acceptPDPA() {
            pdpaAccepted = true;
            sessionStorage.setItem('pdpa_consent', 'accepted');
            document.getElementById('pdpaModal').style.display = 'none';
            initWebcam();
            loadQuizData();
            showAlert('✅', 'Welcome', 'Thank you for accepting. You may now proceed with the quiz.');
        }

        function declinePDPA() {
            sessionStorage.setItem('pdpa_consent', 'declined');
            showAlert('👋', 'Thank You', 'You have declined the data collection consent. You will be redirected to our homepage.');
            setTimeout(() => {
                window.location.href = 'https://www.fusion.co.th';
            }, 2000);
        }

        // Exit quiz function
        function exitQuiz() {
            showAlert('👋', 'Thank You', 'Thank you for your participation!');
            setTimeout(() => {
                // Clear session data
                sessionStorage.removeItem('pdpa_consent');
                // Stop webcam
                stopMonitoring();
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                }
                // Redirect
                window.location.href = 'https://www.fusion.co.th';
            }, 2000);
        }

        // Admin password functions
        async function hasAdminSession() {
            const formData = new FormData();
            formData.append('action', 'admin_session_status');
            const response = await fetch('', { method: 'POST', body: formData });
            const result = await response.json();
            return result.authenticated === true;
        }

        function promptAdminLogin() {
            sessionStorage.removeItem('admin_authenticated');
            document.getElementById('adminPasswordModal').style.display = 'block';
            document.getElementById('adminPasswordInput').value = '';
            document.getElementById('passwordError').style.display = 'none';
            document.getElementById('adminPasswordInput').focus();
        }

        async function openAdminPanel() {
            if (sessionStorage.getItem('admin_authenticated') === 'true' && await hasAdminSession()) {
                showAdminPanel();
            } else {
                promptAdminLogin();
            }
        }

        function closeAdminPasswordModal() {
            document.getElementById('adminPasswordModal').style.display = 'none';
        }

        async function verifyAdminPassword() {
            const password = document.getElementById('adminPasswordInput').value;
            
            if (!password) {
                document.getElementById('passwordError').textContent = '❌ Please enter a password';
                document.getElementById('passwordError').style.display = 'block';
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'verify_admin_password');
            formData.append('password', password);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    sessionStorage.setItem('admin_authenticated', 'true');
                    closeAdminPasswordModal();
                    showAdminPanel();
                } else {
                    document.getElementById('passwordError').textContent = '❌ Invalid password. Please try again.';
                    document.getElementById('passwordError').style.display = 'block';
                    document.getElementById('adminPasswordInput').value = '';
                    document.getElementById('adminPasswordInput').focus();
                }
            } catch (error) {
                console.error('Error verifying password:', error);
                document.getElementById('passwordError').textContent = '❌ Error verifying password. Please try again.';
                document.getElementById('passwordError').style.display = 'block';
            }
        }

        function showAdminPanel() {
            document.getElementById('adminModal').style.display = 'block';
            loadCategoriesAdmin();
        }

        // Load quiz data and render
        async function loadQuizData() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=load_quiz_data'
                });
                const result = await response.json();
                if (result.success) {
                    quizData = result.data;
                    renderQuizzes();
                }
            } catch (error) {
                console.error('Error loading quiz data:', error);
            }
        }

        // Render quizzes in sidebar
        function renderQuizzes() {
            const container = document.getElementById('quizContainer');
            container.innerHTML = '';
            
            quizData.categories.forEach(category => {
                const categoryDiv = document.createElement('div');
                categoryDiv.className = 'quiz-category';
                
                const titleDiv = document.createElement('div');
                titleDiv.className = 'category-title';
                titleDiv.textContent = category.name;
                categoryDiv.appendChild(titleDiv);
                
                category.quizzes.forEach((quiz, index) => {
                    const button = document.createElement('button');
                    button.className = `quiz-btn ${category.color}`;
                    button.textContent = quiz.name;
                    button.setAttribute('data-url', quiz.url);
                    button.setAttribute('data-external', quiz.url.includes('preply.com') ? 'true' : 'false');
                    button.onclick = function() { startQuiz(this); };
                    categoryDiv.appendChild(button);
                });
                
                container.appendChild(categoryDiv);
            });
        }

        // Initialize webcam
        async function initWebcam() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        width: { ideal: 640 },
                        height: { ideal: 480 }
                    } 
                });
                
                const video = document.getElementById('webcamVideo');
                video.srcObject = stream;
                
                document.getElementById('webcamStatus').innerHTML = '✅ Camera Active';
                document.getElementById('webcamStatus').style.background = 'rgba(76, 175, 80, 0.8)';
                
            } catch (err) {
                console.error('Error accessing webcam:', err);
                document.getElementById('webcamStatus').innerHTML = '❌ Camera Error';
                document.getElementById('webcamStatus').style.background = 'rgba(244, 67, 54, 0.8)';
                showAlert('⚠️', 'Camera Required', 'Please allow camera access to take the quiz.');
            }
        }

        // Capture image from webcam
        function captureImage(type = 'start') {
            const video = document.getElementById('webcamVideo');
            const canvas = document.getElementById('canvas');
            const context = canvas.getContext('2d');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const imageData = canvas.toDataURL('image/png');
            saveImageToServer(imageData, type);
            return imageData;
        }

        // Save image to server
        async function saveImageToServer(imageData, type) {
            const formData = new FormData();
            formData.append('action', 'save_image');
            formData.append('image', imageData);
            formData.append('type', type);
            formData.append('user_id', userId);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    console.log('Image saved:', data.filename);
                }
            } catch (error) {
                console.error('Error saving image:', error);
            }
        }

        // Detect cheating
        function detectCheating() {
            const video = document.getElementById('webcamVideo');
            const canvas = document.getElementById('canvas');
            const context = canvas.getContext('2d');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
            const data = imageData.data;
            
            let totalBrightness = 0;
            let pixelCount = 0;
            
            for (let i = 0; i < data.length; i += 4) {
                const brightness = (data[i] + data[i + 1] + data[i + 2]) / 3;
                totalBrightness += brightness;
                pixelCount++;
            }
            
            const avgBrightness = totalBrightness / pixelCount;
            
            if (avgBrightness < 30 || avgBrightness > 250) {
                noFaceWarningCount++;
                
                if (noFaceWarningCount > 3) {
                    captureImage('cheating_suspected');
                    showAlert('🚨', 'Cheating Detected!', 'No face detected or suspicious activity. This incident has been recorded.');
                    noFaceWarningCount = 0;
                }
            } else {
                noFaceWarningCount = 0;
            }
        }

        // Start monitoring
        function startMonitoring() {
            if (!faceDetectionInterval) {
                faceDetectionInterval = setInterval(() => {
                    if (isQuizActive) {
                        detectCheating();
                    }
                }, 5000);
            }
        }

        // Stop monitoring
        function stopMonitoring() {
            if (faceDetectionInterval) {
                clearInterval(faceDetectionInterval);
                faceDetectionInterval = null;
            }
        }

        // Start quiz
        function startQuiz(button) {
            const url = button.getAttribute('data-url');
            const isExternal = button.getAttribute('data-external') === 'true';
            
            showAlert('📸', 'Starting Quiz', '1. Camera will capture your photo\n2. Click OK to begin the quiz\n3. Stay in front of the camera');
            
            setTimeout(() => {
                captureImage('start');
                button.disabled = true;
                
                if (isExternal) {
                    window.open(url, '_blank');
                } else {
                    document.getElementById('quizFrame').src = url;
                }
                
                isQuizActive = true;
                startMonitoring();
                closeAlert();
            }, 100);
        }

        // Show alert
        function showAlert(icon, title, message) {
            document.getElementById('alertIcon').textContent = icon;
            document.getElementById('alertTitle').textContent = title;
            document.getElementById('alertMessage').textContent = message;
            document.getElementById('alertOverlay').style.display = 'flex';
        }

        // Close alert
        function closeAlert() {
            document.getElementById('alertOverlay').style.display = 'none';
        }

        // Admin functions
        function closeAdminPanel() {
            document.getElementById('adminModal').style.display = 'none';
        }

        async function loadCategoriesAdmin() {
            const response = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=load_quiz_data'
            });
            const result = await response.json();
            if (result.success) {
                quizData = result.data;
                renderCategoriesAdmin();
            }
        }

        function renderCategoriesAdmin() {
            const container = document.getElementById('categoriesList');
            container.innerHTML = '';
            
            quizData.categories.forEach((category, catIndex) => {
                const categoryDiv = document.createElement('div');
                categoryDiv.className = 'category-item';
                categoryDiv.innerHTML = `
                    <div class="item-header">
                        <span class="item-title">${category.name}</span>
                        <div class="item-actions">
                            <button class="btn btn-primary" onclick="moveCategoryUp(${category.id}, ${catIndex})">Move Up</button>
                            <button class="btn btn-warning" onclick="editCategory(${category.id})">Edit</button>
                            <button class="btn btn-success" onclick="showAddQuizForm(${category.id})">+ Add Quiz</button>
                            <button class="btn btn-danger" onclick="deleteCategory(${category.id})">Delete</button>
                        </div>
                    </div>
                    <div class="quiz-list" id="quizList${category.id}"></div>
                `;
                container.appendChild(categoryDiv);
                
                // Render quizzes
                const quizList = document.getElementById(`quizList${category.id}`);
                category.quizzes.forEach((quiz, quizIndex) => {
                    const quizDiv = document.createElement('div');
                    quizDiv.className = 'quiz-item';
                    quizDiv.innerHTML = `
                        <div class="item-header">
                            <div>
                                <div class="item-title">${quiz.name}</div>
                                <small style="color: #666;">${quiz.url}</small>
                            </div>
                            <div class="item-actions">
                                <button class="btn btn-warning" onclick="editQuiz(${category.id}, ${quizIndex})">Edit</button>
                                <button class="btn btn-danger" onclick="deleteQuiz(${category.id}, ${quizIndex})">Delete</button>
                            </div>
                        </div>
                    `;
                    quizList.appendChild(quizDiv);
                });
            });
        }

        function showAddCategoryForm() {
            document.getElementById('addCategoryForm').style.display = 'block';
        }

        function hideAddCategoryForm() {
            document.getElementById('addCategoryForm').style.display = 'none';
        }

        async function addCategory() {
            const name = document.getElementById('newCategoryName').value;
            const color = document.getElementById('newCategoryColor').value;
            
            if (!name) {
                alert('Please enter category name');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_category');
            formData.append('name', name);
            formData.append('color', color);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                quizData = result.data;
                renderCategoriesAdmin();
                renderQuizzes();
                hideAddCategoryForm();
                document.getElementById('newCategoryName').value = '';
            }
        }

        function editCategory(categoryId) {
            const category = quizData.categories.find(c => c.id === categoryId);
            if (category) {
                document.getElementById('editCategoryId').value = category.id;
                document.getElementById('editCategoryName').value = category.name;
                document.getElementById('editCategoryColor').value = category.color;
                document.getElementById('editCategoryModal').style.display = 'block';
            }
        }

        function closeEditCategoryModal() {
            document.getElementById('editCategoryModal').style.display = 'none';
        }

        async function saveEditCategory() {
            const id = document.getElementById('editCategoryId').value;
            const name = document.getElementById('editCategoryName').value;
            const color = document.getElementById('editCategoryColor').value;
            
            const formData = new FormData();
            formData.append('action', 'edit_category');
            formData.append('category_id', id);
            formData.append('name', name);
            formData.append('color', color);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                quizData = result.data;
                renderCategoriesAdmin();
                renderQuizzes();
                closeEditCategoryModal();
            }
        }

        async function moveCategoryUp(categoryId, catIndex) {
            if (catIndex === 0) {
                alert('บนสุดแล้วเลื่อนไม่ได้');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'move_category_up');
            formData.append('category_id', categoryId);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                quizData = result.data;
                renderCategoriesAdmin();
                renderQuizzes();
            } else if (result.error === 'top') {
                alert('บนสุดแล้วเลื่อนไม่ได้');
            } else {
                alert(result.error || 'Failed to move category');
            }
        }

        async function deleteCategory(categoryId) {
            if (!confirm('Are you sure you want to delete this category and all its quizzes?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_category');
            formData.append('category_id', categoryId);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                quizData = result.data;
                renderCategoriesAdmin();
                renderQuizzes();
            }
        }

        function showAddQuizForm(categoryId) {
            document.getElementById('addQuizCategoryId').value = categoryId;
            document.getElementById('addQuizModal').style.display = 'block';
        }

        function closeAddQuizModal() {
            document.getElementById('addQuizModal').style.display = 'none';
        }

        async function saveAddQuiz() {
            const categoryId = document.getElementById('addQuizCategoryId').value;
            const name = document.getElementById('addQuizName').value;
            const url = document.getElementById('addQuizUrl').value;
            
            if (!name || !url) {
                alert('Please fill in all fields');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_quiz');
            formData.append('category_id', categoryId);
            formData.append('name', name);
            formData.append('url', url);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                quizData = result.data;
                renderCategoriesAdmin();
                renderQuizzes();
                closeAddQuizModal();
                document.getElementById('addQuizName').value = '';
                document.getElementById('addQuizUrl').value = '';
            }
        }

        function editQuiz(categoryId, quizIndex) {
            const category = quizData.categories.find(c => c.id === categoryId);
            if (category && category.quizzes[quizIndex]) {
                const quiz = category.quizzes[quizIndex];
                document.getElementById('editQuizCategoryId').value = categoryId;
                document.getElementById('editQuizIndex').value = quizIndex;
                document.getElementById('editQuizName').value = quiz.name;
                document.getElementById('editQuizUrl').value = quiz.url;
                document.getElementById('editQuizModal').style.display = 'block';
            }
        }

        function closeEditQuizModal() {
            document.getElementById('editQuizModal').style.display = 'none';
        }

        async function saveEditQuiz() {
            const categoryId = document.getElementById('editQuizCategoryId').value;
            const quizIndex = document.getElementById('editQuizIndex').value;
            const name = document.getElementById('editQuizName').value;
            const url = document.getElementById('editQuizUrl').value;
            
            const formData = new FormData();
            formData.append('action', 'edit_quiz');
            formData.append('category_id', categoryId);
            formData.append('quiz_index', quizIndex);
            formData.append('name', name);
            formData.append('url', url);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                quizData = result.data;
                renderCategoriesAdmin();
                renderQuizzes();
                closeEditQuizModal();
            }
        }

        async function deleteQuiz(categoryId, quizIndex) {
            if (!confirm('Are you sure you want to delete this quiz?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_quiz');
            formData.append('category_id', categoryId);
            formData.append('quiz_index', quizIndex);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                quizData = result.data;
                renderCategoriesAdmin();
                renderQuizzes();
            }
        }

        async function loadCaptures() {
            const response = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_captures'
            });
            const result = await response.json();
            
            if (result.success) {
                const container = document.getElementById('capturesGrid');
                container.innerHTML = '';
                
                if (result.images.length === 0) {
                    container.innerHTML = '<p style="padding: 20px; text-align: center; color: #666;">No captures found</p>';
                    return;
                }
                
                result.images.forEach(image => {
                    const card = document.createElement('div');
                    card.className = 'image-card';

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'capture-check';
                    checkbox.value = image.name;
                    checkbox.title = 'Select capture';

                    const img = document.createElement('img');
                    img.src = image.path;
                    img.alt = image.name;

                    const info = document.createElement('div');
                    info.className = 'image-info';
                    const name = document.createElement('div');
                    name.className = 'image-name';
                    name.textContent = image.name;
                    const date = document.createElement('div');
                    date.className = 'image-date';
                    date.textContent = image.date;
                    const button = document.createElement('button');
                    button.className = 'btn btn-danger';
                    button.style.width = '100%';
                    button.textContent = 'Delete';
                    button.addEventListener('click', () => deleteImage(image.name));
                    info.append(name, date, button);

                    card.append(checkbox, img, info);
                    container.appendChild(card);
                });
            }
        }

        function setAllCapturesSelected(selected) {
            const boxes = document.querySelectorAll('.capture-check');
            if (boxes.length === 0) {
                alert('Load captures first.');
                return;
            }
            boxes.forEach(box => {
                box.checked = selected;
            });
        }

        async function deleteSelectedCaptures() {
            const filenames = [...document.querySelectorAll('.capture-check:checked')].map(box => box.value);
            if (filenames.length === 0) {
                alert('Select at least one capture.');
                return;
            }
            if (!confirm(`Delete ${filenames.length} selected capture(s)? This cannot be undone.`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete_captures');
            filenames.forEach(filename => formData.append('filenames[]', filename));

            const response = await fetch('', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) {
                loadCaptures();
            } else if (result.error === 'Admin login required') {
                alert('Admin login required. Please enter the admin password again.');
                promptAdminLogin();
            } else {
                alert(result.error || 'Failed to delete selected captures.');
            }
        }

        async function deleteAllCaptures() {
            if (!confirm('Delete ALL captures? This cannot be undone.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete_all_captures');
            const response = await fetch('', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) {
                loadCaptures();
            } else if (result.error === 'Admin login required') {
                alert('Admin login required. Please enter the admin password again.');
                promptAdminLogin();
            } else {
                alert(result.error || 'Failed to delete captures.');
            }
        }

        async function deleteImage(filename) {
            if (!confirm('Are you sure you want to delete this image?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_image');
            formData.append('filename', filename);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                loadCaptures();
            } else if (result.error === 'Admin login required') {
                alert('Admin login required. Please enter the admin password again.');
                promptAdminLogin();
            }
        }

        // Initialize - removed auto-init, now handled by PDPA consent
        window.addEventListener('beforeunload', () => {
            stopMonitoring();
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
        });
    </script>
</body>
</html>
