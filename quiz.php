<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

$mail = new PHPMailer(true);

session_start();

// Configuration
$ADMIN_EMAIL = "Quiztest@fusion.co.th";
$QUIZ_MAPPING_FILE = "quiz_mapping.csv";
$QUIZZES_FOLDER = "quizzes/";
$TIME_PER_QUESTION = 60;
$ADMIN_PASSWORD = "IloveFusion@1234";
$DEFAULT_QUESTIONS_TO_LOAD = 10;
$LOG_FILE = "/var/log/nginx/quiz.log";

// Logging function
function writeLog($level, $message) {
    global $LOG_FILE;
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    
    if (!@file_put_contents($LOG_FILE, $logMessage, FILE_APPEND)) {
        $localLog = __DIR__ . "/quiz_local.log";
        @file_put_contents($localLog, $logMessage, FILE_APPEND);
    }
}

// Create quizzes folder if it doesn't exist
if (!is_dir($QUIZZES_FOLDER)) {
    mkdir($QUIZZES_FOLDER, 0755, true);
    writeLog('INFO', "Created quizzes folder: $QUIZZES_FOLDER");
}

// Function to load quiz mapping
function loadQuizMapping($filename) {
    $quizzes = [];
    
    writeLog('DEBUG', "Attempting to load quiz mapping from: $filename");
    
    if (!file_exists($filename)) {
        writeLog('INFO', "Quiz mapping file does not exist: $filename");
        return $quizzes;
    }
    
    if (($handle = fopen($filename, "r")) !== FALSE) {
        $headers = fgetcsv($handle);
        writeLog('DEBUG', "Quiz mapping headers: " . json_encode($headers));
        
        $lineNum = 1;
        while (($data = fgetcsv($handle)) !== FALSE) {
            $lineNum++;
            if (count($data) >= 4) {
                $quizzes[] = [
                    "title" => $data[0],
                    "filename" => $data[1],
                    "questions_count" => (int)$data[2],
                    "passing_score" => (int)$data[3]
                ];
                writeLog('DEBUG', "Loaded quiz from line $lineNum: Title='{$data[0]}', File='{$data[1]}', Count={$data[2]}, PassingScore={$data[3]}%");
            } else {
                writeLog('ERROR', "Invalid quiz mapping data at line $lineNum: " . json_encode($data));
            }
        }
        fclose($handle);
        
        writeLog('INFO', "Loaded " . count($quizzes) . " quizzes from mapping file");
    } else {
        writeLog('ERROR', "Failed to open quiz mapping file: $filename");
    }
    
    return $quizzes;
}

// Function to auto-discover quiz files and create mapping
function autoDiscoverQuizzes($quizzesFolder, $mappingFile, $defaultCount) {
    writeLog('INFO', "Auto-discovering quiz files in: $quizzesFolder");
    
    if (!is_dir($quizzesFolder)) {
        writeLog('ERROR', "Quizzes folder does not exist: $quizzesFolder");
        return;
    }
    
    $files = glob($quizzesFolder . "*.csv");
    writeLog('DEBUG', "Found " . count($files) . " CSV files in quizzes folder");
    
    if (empty($files)) {
        writeLog('INFO', "No CSV files found in quizzes folder");
        return;
    }
    
    $existingMappings = [];
    if (file_exists($mappingFile)) {
        if (($handle = fopen($mappingFile, "r")) !== FALSE) {
            fgetcsv($handle);
            while (($data = fgetcsv($handle)) !== FALSE) {
                if (count($data) >= 2) {
                    $existingMappings[$data[1]] = true;
                }
            }
            fclose($handle);
        }
    }
    
    writeLog('DEBUG', "Existing mappings: " . json_encode(array_keys($existingMappings)));
    
    $newQuizzesAdded = 0;
    foreach ($files as $file) {
        $filename = basename($file);
        
        if (isset($existingMappings[$filename])) {
            writeLog('DEBUG', "Quiz already mapped, skipping: $filename");
            continue;
        }
        
        $title = str_replace(['_', '.csv'], [' ', ''], $filename);
        $title = preg_replace('/\s+\d+$/', '', $title);
        $title = ucwords($title);
        
        writeLog('INFO', "Auto-adding quiz: $title (file: $filename)");
        saveQuizMapping($mappingFile, $title, $filename, $defaultCount, 70);
        $newQuizzesAdded++;
    }
    
    if ($newQuizzesAdded > 0) {
        writeLog('INFO', "Auto-discovered and added $newQuizzesAdded new quizzes");
    } else {
        writeLog('DEBUG', "No new quizzes to add");
    }
}

// Function to save quiz mapping
function saveQuizMapping($filename, $title, $csvFilename, $questionsCount, $passingScore = 70) {
    $fileExists = file_exists($filename);
    
    writeLog('DEBUG', "Saving quiz mapping: Title='$title', File='$csvFilename', Count=$questionsCount, PassingScore=$passingScore%");
    
    $handle = fopen($filename, "a");
    
    if (!$fileExists) {
        fputcsv($handle, ["Quiz Title", "CSV Filename", "Questions to Load", "Passing Score (%)"]);
        writeLog('INFO', "Created new quiz mapping file: $filename");
    }
    
    fputcsv($handle, [$title, $csvFilename, $questionsCount, $passingScore]);
    fclose($handle);
    
    writeLog('INFO', "Quiz mapping saved successfully");
}

// Function to load questions from CSV and randomize
function loadQuestionsFromCSV($filename, $questionsToLoad = null) {
    $questions = [];
    
    writeLog('DEBUG', "Loading questions from: $filename (limit: $questionsToLoad)");
    
    if (!file_exists($filename)) {
        writeLog('ERROR', "Quiz file does not exist: $filename");
        return $questions;
    }
    
    if (($handle = fopen($filename, "r")) !== FALSE) {
        $headers = fgetcsv($handle);
        writeLog('DEBUG', "Quiz CSV headers: " . json_encode($headers));
        
        $lineNum = 1;
        while (($data = fgetcsv($handle)) !== FALSE) {
            $lineNum++;
            if (count($data) >= 6) {
                $questions[] = [
                    "question" => $data[0],
                    "options" => [$data[1], $data[2], $data[3], $data[4]],
                    "correct" => (int)$data[5]
                ];
            } else {
                writeLog('ERROR', "Invalid question data at line $lineNum in $filename: " . json_encode($data));
            }
        }
        fclose($handle);
        
        writeLog('INFO', "Loaded " . count($questions) . " questions from $filename");
    } else {
        writeLog('ERROR', "Failed to open quiz file: $filename");
    }
    
    shuffle($questions);
    writeLog('DEBUG', "Questions shuffled randomly");
    
    if ($questionsToLoad !== null && $questionsToLoad > 0 && count($questions) > $questionsToLoad) {
        $questions = array_slice($questions, 0, $questionsToLoad);
        writeLog('INFO', "Limited questions to $questionsToLoad from total available");
    }
    
    return $questions;
}

// Handle admin panel actions
if (isset($_POST['admin_action'])) {
    if ($_POST['admin_action'] === 'login') {
        writeLog('INFO', "Admin login attempt");
        if ($_POST['admin_password'] === $ADMIN_PASSWORD) {
            $_SESSION['admin_logged_in'] = true;
            writeLog('INFO', "Admin login successful");
            header('Location: ' . $_SERVER['PHP_SELF'] . '?admin=1');
            exit;
        } else {
            writeLog('ERROR', "Admin login failed - invalid password");
            $admin_error = "Invalid password!";
        }
    } elseif ($_POST['admin_action'] === 'upload_csv') {
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
            writeLog('INFO', "Admin attempting to upload quiz");
            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
                $quizTitle = trim($_POST['quiz_title']);
                $questionsToLoad = (int)$_POST['questions_to_load'];
                $passingScore = (int)$_POST['passing_score'];
                
                writeLog('DEBUG', "Upload details - Title: '$quizTitle', Questions: $questionsToLoad, PassingScore: $passingScore%");
                
                if (empty($quizTitle)) {
                    $upload_error = "Please enter a quiz title.";
                    writeLog('ERROR', "Quiz upload failed - no title provided");
                } elseif ($questionsToLoad <= 0) {
                    $upload_error = "Please enter a valid number of questions to load.";
                    writeLog('ERROR', "Quiz upload failed - invalid questions count: $questionsToLoad");
                } elseif ($passingScore <= 0 || $passingScore > 100) {
                    $upload_error = "Please enter a valid passing score between 1 and 100.";
                    writeLog('ERROR', "Quiz upload failed - invalid passing score: $passingScore");
                } else {
                    $fileTmpPath = $_FILES['csv_file']['tmp_name'];
                    $fileName = $_FILES['csv_file']['name'];
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    
                    if ($fileExtension === 'csv') {
                        $newFilename = preg_replace('/[^a-z0-9_-]/i', '_', $quizTitle) . '_' . time() . '.csv';
                        $destination = $QUIZZES_FOLDER . $newFilename;
                        
                        writeLog('DEBUG', "Moving uploaded file to: $destination");
                        
                        if (move_uploaded_file($fileTmpPath, $destination)) {
                            saveQuizMapping($QUIZ_MAPPING_FILE, $quizTitle, $newFilename, $questionsToLoad, $passingScore);
                            $upload_success = "Quiz '$quizTitle' uploaded successfully!";
                            writeLog('INFO', "Quiz uploaded successfully: $newFilename");
                        } else {
                            $upload_error = "Error moving uploaded file.";
                            writeLog('ERROR', "Failed to move uploaded file to $destination");
                        }
                    } else {
                        $upload_error = "Please upload a CSV file.";
                        writeLog('ERROR', "Invalid file type uploaded: $fileExtension");
                    }
                }
            } else {
                $uploadError = isset($_FILES['csv_file']['error']) ? $_FILES['csv_file']['error'] : 'unknown';
                $upload_error = "Error uploading file.";
                writeLog('ERROR', "File upload error: $uploadError");
            }
        }
    } elseif ($_POST['admin_action'] === 'logout') {
        writeLog('INFO', "Admin logged out");
        unset($_SESSION['admin_logged_in']);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Handle PDPA consent
if (isset($_POST['accept_pdpa'])) {
    $_SESSION['pdpa_accepted'] = true;
    writeLog('INFO', "User accepted PDPA consent");
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle user login (name and email)
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
    $name = trim($_POST['name']);
    
    writeLog('INFO', "User login attempt: Name='$name', Email=" . ($email ? $email : 'invalid email'));
    
    if ($email && !empty($name)) {
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name'] = $name;
        writeLog('INFO', "User logged in successfully: $name ($email)");
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        if (!$email) {
            $error = "Please enter a valid email address.";
            writeLog('ERROR', "Invalid email format provided");
        } else {
            $error = "Please enter your name.";
            writeLog('ERROR', "Name not provided");
        }
    }
}

// Auto-discover quizzes if mapping file doesn't exist or is empty
autoDiscoverQuizzes($QUIZZES_FOLDER, $QUIZ_MAPPING_FILE, $DEFAULT_QUESTIONS_TO_LOAD);

// Handle quiz selection
if (isset($_POST['select_quiz'])) {
    $selectedQuizIndex = (int)$_POST['quiz_index'];
    $availableQuizzes = loadQuizMapping($QUIZ_MAPPING_FILE);
    
    writeLog('INFO', "User selecting quiz at index: $selectedQuizIndex");
    
    if (isset($availableQuizzes[$selectedQuizIndex])) {
        $selectedQuiz = $availableQuizzes[$selectedQuizIndex];
        writeLog('INFO', "User selected quiz: {$selectedQuiz['title']}");
        
        $_SESSION['selected_quiz'] = $selectedQuiz;
        $_SESSION['quiz_questions'] = loadQuestionsFromCSV(
            $QUIZZES_FOLDER . $selectedQuiz['filename'],
            $selectedQuiz['questions_count']
        );
        $_SESSION['current_question'] = 0;
        $_SESSION['quiz_answers'] = [];
        $_SESSION['start_time'] = time();
        
        writeLog('INFO', "Quiz loaded with " . count($_SESSION['quiz_questions']) . " questions");
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        writeLog('ERROR', "Invalid quiz index selected: $selectedQuizIndex");
    }
}

// Handle return to quiz selection
if (isset($_GET['select_another'])) {
    writeLog('INFO', "User returning to quiz selection");
    unset($_SESSION['selected_quiz']);
    unset($_SESSION['quiz_questions']);
    unset($_SESSION['current_question']);
    unset($_SESSION['quiz_answers']);
    unset($_SESSION['start_time']);
    unset($_SESSION['quiz_results']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Load available quizzes
$availableQuizzes = loadQuizMapping($QUIZ_MAPPING_FILE);
writeLog('DEBUG', "Available quizzes loaded: " . count($availableQuizzes));

// Get questions from session
$questions = isset($_SESSION['quiz_questions']) ? $_SESSION['quiz_questions'] : [];
$selectedQuiz = isset($_SESSION['selected_quiz']) ? $_SESSION['selected_quiz'] : null;

// Handle quiz navigation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'next_question' || $_POST['action'] === 'prev_question')) {
    $currentIndex = (int)$_POST['current_index'];
    $answer = isset($_POST["answer"]) ? (int)$_POST["answer"] : -1;
    
    writeLog('DEBUG', "Navigation: action={$_POST['action']}, question=$currentIndex, answer=$answer");
    
    if (!isset($_SESSION['quiz_answers'])) {
        $_SESSION['quiz_answers'] = [];
    }
    $_SESSION['quiz_answers'][$currentIndex] = $answer;
    
    if ($_POST['action'] === 'next_question') {
        $_SESSION['current_question'] = $currentIndex + 1;
        writeLog('INFO', "User moved to next question: " . ($_SESSION['current_question'] + 1));
    } else {
        $_SESSION['current_question'] = max(0, $currentIndex - 1);
        writeLog('INFO', "User moved to previous question: " . ($_SESSION['current_question'] + 1));
    }
    $no_questions = count($questions);
    $i_question = $_SESSION['current_question']; 
        writeLog('INFO', "current question: $i_question  count: $no_questions ");
    // Check if quiz is complete
    if ($_SESSION['current_question'] >= count($questions)) {
        writeLog('INFO', "User completed quiz, calculating results");
        
        $score = 0;
        $total = count($questions);
        
        foreach ($questions as $index => $question) {
            $userAnswer = isset($_SESSION['quiz_answers'][$index]) ? $_SESSION['quiz_answers'][$index] : -1;
            if ($userAnswer === $question['correct']) {
                $score++;
            }
        }
        
        $percentage = round(($score / $total) * 100, 2);
        $timeSpent = time() - $_SESSION['start_time'];
        $minutes = floor($timeSpent / 60);
        $seconds = $timeSpent % 60;
        
        // Determine pass/fail based on quiz passing score
        $passingScore = $selectedQuiz['passing_score'];
        $passed = $percentage >= $passingScore;
        $status = $passed ? "PASSED" : "FAILED";
        
        $_SESSION['quiz_results'] = [
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
            'time_spent' => "$minutes minutes $seconds seconds",
            'answers' => $_SESSION['quiz_answers'],
            'quiz_title' => $selectedQuiz['title'],
            'passed' => $passed,
            'passing_score' => $passingScore,
            'status' => $status
        ];
        
        $userEmail = $_SESSION['user_email'];
        $userName = $_SESSION['user_name'];
        writeLog('INFO', "Quiz completed by $userName ($userEmail): Score=$score/$total ($percentage%), Status=$status, Time={$minutes}m{$seconds}s");
        
        $quizTitle = $selectedQuiz['title'];
        $subject = "Your $quizTitle Results - $status";
	// --OPPO email
	//
    // SMTP settings
    $GMAIL_EMAIL = "kcommerce@gmail.com";
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $GMAIL_EMAIL; // Your Gmail address
    $mail->Password   = 'lgjp drlv rvjq smjv';    // App Password from Google
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // or 'tls'
    $mail->Port       = 587;

	//
        // Create email message
        $message = "Hello $userName,\n\n";
        $message .= "Thank you for taking the $quizTitle!\n\n";
        $message .= "=== YOUR RESULTS ===\n";
        $message .= "Score: $score out of $total ($percentage%)\n";
        $message .= "Passing Score Required: $passingScore%\n";
        $message .= "Status: $status " . ($passed ? "✓" : "✗") . "\n";
        $message .= "Time Spent: $minutes minutes $seconds seconds\n\n";
        
        if ($passed) {
            $message .= "Congratulations! You have successfully passed the quiz.\n\n";
        } else {
            $message .= "Unfortunately, you did not meet the passing score. Please review the material and try again.\n\n";
        }
        
        $message .= "Keep learning!\n\n";
        $message .= "Best regards,\nQuiz Team";
        
        $headers = "From: $GMAIL_EMAIL\r\n";
        $headers .= "Cc: $ADMIN_EMAIL\r\n";
        $headers .= "Reply-To: $GMAIL_EMAIL\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
	try {
       	$mail->send();
            writeLog('INFO', "Results email sent to: $userEmail (CC: $ADMIN_EMAIL)");

	} catch (Exception $e)
	{
            writeLog('ERROR', "Failed to send results email to: $userEmail");
            writeLog('ERROR', "Mailer Error: $mail->ErrorInfo");

	}
	/*
        if (mail($userEmail, $subject, $message, $headers)) {
            writeLog('INFO', "Results email sent to: $userEmail (CC: $ADMIN_EMAIL)");
        } else {
            writeLog('ERROR', "Failed to send results email to: $userEmail");
	}
*/
        
        header('Location: ' . $_SERVER['PHP_SELF'] . '?results=1');
        exit;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    $email = isset($_SESSION['user_email']) ? $_SESSION['user_email'] : 'unknown';
    writeLog('INFO', "User logged out: $email");
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

writeLog('DEBUG', "Page loaded - Session state: PDPA=" . (isset($_SESSION['pdpa_accepted']) ? 'yes' : 'no') . 
         ", User=" . (isset($_SESSION['user_email']) ? $_SESSION['user_email'] : 'none') . 
         ", Quiz=" . (isset($_SESSION['selected_quiz']) ? $_SESSION['selected_quiz']['title'] : 'none'));

$showResults = isset($_GET['results']) && isset($_SESSION['quiz_results']);
$currentQuestionIndex = isset($_SESSION['current_question']) ? $_SESSION['current_question'] : 0;
$currentQuestion = isset($questions[$currentQuestionIndex]) ? $questions[$currentQuestionIndex] : null;
$showAdminPanel = isset($_GET['admin']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Quiz System</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 900px;
            width: 100%;
            padding: 40px;
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h1 {
            color: #667eea;
            text-align: center;
            margin-bottom: 10px;
            font-size: 2.5em;
        }

        h2 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 1.8em;
        }

        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 1.1em;
        }

        .login-form {
            max-width: 400px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"],
        input[type="number"],
        input[type="file"] {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        input[type="email"]:focus,
        input[type="password"]:focus,
        input[type="text"]:focus,
        input[type="number"]:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #667eea;
            color: #667eea;
            margin-top: 15px;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-danger {
            background: #dc3545;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-success {
            background: #28a745;
        }

        .btn-success:hover {
            background: #218838;
        }

        .question-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            border-left: 5px solid #667eea;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .question-number {
            color: #667eea;
            font-weight: bold;
            font-size: 1.1em;
        }

        .timer {
            background: #667eea;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 1.1em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .timer.warning {
            background: #ff6b6b;
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        .question-text {
            font-size: 1.3em;
            color: #333;
            margin-bottom: 25px;
            font-weight: 600;
            line-height: 1.5;
        }

        .option {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            font-size: 1.05em;
        }

        .option:hover {
            border-color: #667eea;
            background: #f0f4ff;
            transform: translateX(5px);
        }

        .option input[type="radio"] {
            margin-right: 15px;
            cursor: pointer;
            width: 20px;
            height: 20px;
        }

        .option.selected {
            border-color: #667eea;
            background: #e8eeff;
            font-weight: 600;
        }

        .user-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid white;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .progress-bar {
            background: #e0e0e0;
            height: 8px;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .progress-fill {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100%;
            transition: width 0.3s ease;
        }

        .results-card {
            text-align: center;
            padding: 40px;
        }

        .score-display {
            font-size: 4em;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 20px 0;
        }

        .result-info {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
        }

        .result-info p {
            margin: 10px 0;
            font-size: 1.1em;
            color: #333;
        }

        .status-badge {
            display: inline-block;
            padding: 10px 30px;
            border-radius: 25px;
            font-size: 1.3em;
            font-weight: bold;
            margin: 15px 0;
        }

        .status-passed {
            background: #d4edda;
            color: #155724;
            border: 2px solid #28a745;
        }

        .status-failed {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #dc3545;
        }

        .error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .success-icon {
            font-size: 5em;
            margin: 20px 0;
        }

        .navigation-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .pdpa-box {
            background: #f8f9fa;
            border: 2px solid #667eea;
            border-radius: 15px;
            padding: 30px;
            margin: 20px 0;
        }

        .pdpa-box h3 {
            color: #667eea;
            margin-bottom: 15px;
        }

        .pdpa-box p {
            color: #333;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .pdpa-box ul {
            margin-left: 20px;
            margin-bottom: 15px;
            color: #555;
        }

        .pdpa-box li {
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .admin-panel {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            margin-top: 20px;
        }

        .admin-panel table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }

        .admin-panel th,
        .admin-panel td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .admin-panel th {
            background: #667eea;
            color: white;
        }

        .quiz-card {
            background: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .quiz-card:hover {
            border-color: #667eea;
            background: #f0f4ff;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        }

        .quiz-card h3 {
            color: #667eea;
            margin-bottom: 10px;
        }

        .quiz-card p {
            color: #666;
            margin: 5px 0;
        }

        .quiz-card .quiz-select-btn {
            margin-top: 15px;
            width: 100%;
        }

        @media (max-width: 600px) {
            .container {
                padding: 20px;
            }

            h1 {
                font-size: 1.8em;
            }

            .score-display {
                font-size: 3em;
            }

            .question-text {
                font-size: 1.1em;
            }

            .navigation-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!isset($_SESSION['pdpa_accepted'])): ?>
            <!-- PDPA Consent Page -->
            <h1>🔒 Privacy Notice</h1>
            <p class="subtitle">Personal Data Protection Act (PDPA) Consent</p>

            <div class="pdpa-box">
                <h3>Collection of Personal Information</h3>
                <p>
                    Before you proceed with the quiz, we would like to inform you about how we collect and use your personal information.
                </p>

                <h3>What Information We Collect:</h3>
                <ul>
                    <li>Name</li>
                    <li>Email address</li>
                    <li>Quiz results and scores</li>
                    <li>Time spent on the quiz</li>
                    <li>Answers to quiz questions</li>
                </ul>

                <h3>How We Use Your Information:</h3>
                <ul>
                    <li>To send you your quiz results via email</li>
                    <li>To maintain records of quiz submissions</li>
                    <li>To improve our quiz content and user experience</li>
                    <li>For administrative purposes and communication</li>
                </ul>

                <h3>Data Protection:</h3>
                <p>
                    Your personal information will be stored securely and will not be shared with third parties without your consent. 
                    We are committed to protecting your privacy in accordance with the Personal Data Protection Act.
                </p>

                <p style="margin-top: 20px;">
                    <strong>By clicking "Accept and Continue", you consent to the collection and use of your personal information as described above.</strong>
                </p>
            </div>

            <form method="POST">
                <button type="submit" name="accept_pdpa" class="btn">✓ Accept and Continue</button>
            </form>

        <?php elseif ($showAdminPanel): ?>
            <!-- Admin Panel -->
            <?php if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']): ?>
                <!-- Admin Login -->
                <h1>🔐 Admin Panel</h1>
                <p class="subtitle">Enter admin password to access</p>

                <?php if (isset($admin_error)): ?>
                    <div class="error"><?php echo htmlspecialchars($admin_error); ?></div>
                <?php endif; ?>

                <form method="POST" class="login-form">
                    <input type="hidden" name="admin_action" value="login">
                    <div class="form-group">
                        <label for="admin_password">Admin Password</label>
                        <input type="password" id="admin_password" name="admin_password" required placeholder="Enter admin password">
                    </div>
                    <button type="submit" class="btn">Login</button>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" style="text-decoration: none;">
                        <button type="button" class="btn btn-outline">Back to Quiz</button>
                    </a>
                </form>

            <?php else: ?>
                <!-- Admin Dashboard -->
                <h1>⚙️ Admin Dashboard</h1>
                
                <?php if (isset($upload_success)): ?>
                    <div class="success"><?php echo htmlspecialchars($upload_success); ?></div>
                <?php endif; ?>
                
                <?php if (isset($upload_error)): ?>
                    <div class="error"><?php echo htmlspecialchars($upload_error); ?></div>
                <?php endif; ?>

                <div class="admin-panel">
                    <h2>Upload New Quiz</h2>
                    <p style="margin-bottom: 20px; color: #666;">
                        Upload a CSV file with the following format:<br>
                        <code>question, option1, option2, option3, option4, correct_index</code>
                    </p>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="admin_action" value="upload_csv">
                        
                        <div class="form-group">
                            <label for="quiz_title">Quiz Title</label>
                            <input type="text" id="quiz_title" name="quiz_title" required placeholder="E.g., Linux Commands Quiz">
                        </div>

                        <div class="form-group">
                            <label for="questions_to_load">Number of Questions to Load (Default: <?php echo $DEFAULT_QUESTIONS_TO_LOAD; ?>)</label>
                            <input type="number" id="questions_to_load" name="questions_to_load" value="<?php echo $DEFAULT_QUESTIONS_TO_LOAD; ?>" min="1" required>
                            <small style="color: #666; display: block; margin-top: 5px;">
                                This determines how many questions will be randomly selected from the CSV file for each quiz attempt.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="passing_score">Passing Score (%)</label>
                            <input type="number" id="passing_score" name="passing_score" value="70" min="1" max="100" required>
                            <small style="color: #666; display: block; margin-top: 5px;">
                                Minimum percentage required to pass (1-100).
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="csv_file">Select CSV File</label>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                        </div>
                        
                        <button type="submit" class="btn">Upload Quiz</button>
                    </form>

                    <h2 style="margin-top: 40px;">Available Quizzes (<?php echo count($availableQuizzes); ?> total)</h2>
                    
                    <?php if (count($availableQuizzes) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Quiz Title</th>
                                    <th>CSV Filename</th>
                                    <th>Questions to Load</th>
                                    <th>Passing Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($availableQuizzes as $index => $quiz): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($quiz['title']); ?></td>
                                        <td><?php echo htmlspecialchars($quiz['filename']); ?></td>
                                        <td><?php echo $quiz['questions_count']; ?></td>
                                        <td><?php echo $quiz['passing_score']; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="margin-top: 20px; color: #999; text-align: center;">No quizzes uploaded yet.</p>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 30px; display: flex; gap: 15px;">
                    <form method="POST" style="flex: 1;">
                        <input type="hidden" name="admin_action" value="logout">
                        <button type="submit" class="btn btn-danger">Logout</button>
                    </form>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" style="text-decoration: none; flex: 1;">
                        <button type="button" class="btn btn-secondary" style="width: 100%;">Back to Quiz</button>
                    </a>
                </div>
            <?php endif; ?>

        <?php elseif (!isset($_SESSION['user_email'])): ?>
            <!-- Login Page - Name and Email -->
            <h1>📝 General Quiz System</h1>
            <p class="subtitle">Test your knowledge with our interactive quizzes</p>

            <?php if (count($availableQuizzes) === 0): ?>
                <div class="error">
                    <strong>Notice:</strong> No quizzes are currently available. Please contact the administrator.
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" required placeholder="Enter your full name" <?php echo count($availableQuizzes) === 0 ? 'disabled' : ''; ?>>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="your.email@example.com" <?php echo count($availableQuizzes) === 0 ? 'disabled' : ''; ?>>
                </div>

                <button type="submit" class="btn" <?php echo count($availableQuizzes) === 0 ? 'disabled' : ''; ?>>Continue</button>
            </form>

            <a href="?admin=1" style="text-decoration: none;">
                <button class="btn btn-outline">🔐 Admin Panel</button>
            </a>

        <?php elseif (!isset($_SESSION['selected_quiz'])): ?>
            <!-- Quiz Selection Page -->
            <h1>📚 Select a Quiz</h1>
            <p class="subtitle">Choose from the available quizzes below</p>

            <div class="user-info">
                <span>👤 <?php echo htmlspecialchars($_SESSION['user_name']); ?> (<?php echo htmlspecialchars($_SESSION['user_email']); ?>)</span>
                <a href="?logout=1" style="text-decoration: none;">
                    <button class="logout-btn">Logout</button>
                </a>
            </div>

            <?php if (count($availableQuizzes) > 0): ?>
                <?php foreach ($availableQuizzes as $index => $quiz): ?>
                    <div class="quiz-card">
                        <h3><?php echo htmlspecialchars($quiz['title']); ?></h3>
                        <p>📄 <strong>Questions:</strong> <?php echo $quiz['questions_count']; ?> questions (randomly selected)</p>
                        <p>⏱️ <strong>Time per question:</strong> <?php echo $TIME_PER_QUESTION; ?> seconds</p>
                        <p>📊 <strong>Total time:</strong> Approximately <?php echo ceil(($quiz['questions_count'] * $TIME_PER_QUESTION) / 60); ?> minutes</p>
                        <p>🎯 <strong>Passing Score:</strong> <?php echo $quiz['passing_score']; ?>%</p>
                        <form method="POST">
                            <input type="hidden" name="quiz_index" value="<?php echo $index; ?>">
                            <button type="submit" name="select_quiz" class="btn quiz-select-btn">Start This Quiz →</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="error">No quizzes available. Please contact the administrator.</div>
            <?php endif; ?>

        <?php elseif ($showResults): ?>
            <!-- Results Page -->
            <?php
            $results = $_SESSION['quiz_results'];
            $passed = $results['passed'];
?>
<?php

        $score = 0;
        $total = count($questions);
        
        foreach ($questions as $index => $question) {
            $userAnswer = isset($_SESSION['quiz_answers'][$index]) ? $_SESSION['quiz_answers'][$index] : -1;
            if ($userAnswer === $question['correct']) {
                $score++;
            }
        }
        
        $percentage = round(($score / $total) * 100, 2);
        $timeSpent = time() - $_SESSION['start_time'];
        $minutes = floor($timeSpent / 60);
        $seconds = $timeSpent % 60;
        
        // Determine pass/fail based on quiz passing score
        $passingScore = $selectedQuiz['passing_score'];
        $passed = $percentage >= $passingScore;
        $status = $passed ? "PASSED" : "FAILED";
        
        $_SESSION['quiz_results'] = [
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
            'time_spent' => "$minutes minutes $seconds seconds",
            'answers' => $_SESSION['quiz_answers'],
            'quiz_title' => $selectedQuiz['title'],
            'passed' => $passed,
            'passing_score' => $passingScore,
            'status' => $status
        ];
        
        $userEmail = $_SESSION['user_email'];
        $userName = $_SESSION['user_name'];
        writeLog('INFO', "Quiz completed by $userName ($userEmail): Score=$score/$total ($percentage%), Status=$status, Time={$minutes}m{$seconds}s");
        
        $quizTitle = $selectedQuiz['title'];
        $subject = "Your $quizTitle Results - $status";
	// --OPPO email
	//
    // SMTP settings
    $GMAIL_EMAIL = "kcommerce@gmail.com";
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $GMAIL_EMAIL; // Your Gmail address
    $mail->Password   = 'lgjp drlv rvjq smjv';    // App Password from Google
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // or 'tls'
    $mail->Port       = 587;
    $mail->addAddress($_SESSION['user_email']);
    $mail->addAddress($ADMIN_EMAIL);



	//
        // Create email message
        $message = "Hello $userName,\n\n";
        $message .= "Thank you for taking the $quizTitle!\n\n";
        $message .= "=== YOUR RESULTS ===\n";
        $message .= "Score: $score out of $total ($percentage%)\n";
        $message .= "Passing Score Required: $passingScore%\n";
        $message .= "Status: $status " . ($passed ? "✓" : "✗") . "\n";
        $message .= "Time Spent: $minutes minutes $seconds seconds\n\n";
        
        if ($passed) {
            $message .= "Congratulations! You have successfully passed the quiz.\n\n";
        } else {
            $message .= "Unfortunately, you did not meet the passing score. Please review the material and try again.\n\n";
        }
        
        $message .= "Keep learning!\n\n";
        $message .= "Best regards,\nQuiz Team";
        
        $headers = "From: $GMAIL_EMAIL\r\n";
        $headers .= "Cc: $ADMIN_EMAIL\r\n";
        $headers .= "Reply-To: $GMAIL_EMAIL\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
	try {
    // Email details
    $mail->Subject = "Thank you for taking the $quizTitle!";
    $mail->Body    = $message;
       	$mail->send();
            writeLog('INFO', "Results email sent to: $userEmail (CC: $ADMIN_EMAIL)");

	} catch (Exception $e)
	{
            writeLog('ERROR', "Failed to send results email to: $userEmail");
            writeLog('ERROR', "Mailer Error: $mail->ErrorInfo");

	}

?>
            <div class="results-card">
                <div class="success-icon"><?php echo $passed ? '🎉' : '📚'; ?></div>
                <h1><?php echo $passed ? 'Congratulations!' : 'Keep Learning!'; ?></h1>
                <h2 style="text-align: center; color: #666; margin-bottom: 20px;"><?php echo htmlspecialchars($results['quiz_title']); ?></h2>
                
                <div class="status-badge <?php echo $passed ? 'status-passed' : 'status-failed'; ?>">
                    <?php echo $results['status']; ?> <?php echo $passed ? '✓' : '✗'; ?>
                </div>

                <div class="score-display">
                    <?php echo $results['score']; ?>/<?php echo $results['total']; ?>
                </div>
                <div class="result-info">
                    <p><strong>Percentage:</strong> <?php echo $results['percentage']; ?>%</p>
                    <p><strong>Passing Score Required:</strong> <?php echo $results['passing_score']; ?>%</p>
                    <p><strong>Time Spent:</strong> <?php echo $results['time_spent']; ?></p>
                </div>
                
                <p style="margin: 20px 0; color: #666;">
                    Results have been sent to your email: <strong><?php echo htmlspecialchars($_SESSION['user_email']); ?></strong>
                </p>

                <div style="display: flex; gap: 15px; margin-top: 30px;">
                    <a href="?select_another=1" style="text-decoration: none; flex: 1;">
                        <button class="btn btn-success" style="width: 100%;">📚 Take Another Quiz</button>
                    </a>
                    <a href="?logout=1" style="text-decoration: none; flex: 1;">
                        <button class="btn btn-outline" style="width: 100%;">Logout</button>
                    </a>
                </div>
            </div>

        <?php else: ?>
            <!-- Quiz Page - One Question at a Time -->
            <div class="user-info">
                <div>
                    <div style="font-size: 0.9em; opacity: 0.9;">👤 <?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                    <div style="font-size: 1.1em; font-weight: bold; margin-top: 5px;"><?php echo htmlspecialchars($selectedQuiz['title']); ?></div>
                </div>
                <a href="?logout=1" style="text-decoration: none;">
                    <button class="logout-btn">Logout</button>
                </a>
            </div>

            <!-- Progress Bar -->
            <div class="progress-bar">
                <div class="progress-fill" id="progressBar" style="width: <?php echo ($currentQuestionIndex / count($questions)) * 100; ?>%"></div>
            </div>

            <?php if ($currentQuestion): ?>
                <form method="POST" id="quizForm">
                    <input type="hidden" name="current_index" value="<?php echo $currentQuestionIndex; ?>">
                    <input type="hidden" id="formAction" name="action" value="next_question">

                    <div class="question-card">
                        <div class="question-header">
                            <div class="question-number">
                                Question <?php echo $currentQuestionIndex + 1; ?> of <?php echo count($questions); ?>
                            </div>
                            <div class="timer" id="timer">
                                <span>⏱️</span>
                                <span id="timeDisplay">1:00</span>
                            </div>
                        </div>

                        <div class="question-text"><?php echo htmlspecialchars($currentQuestion['question']); ?></div>

                        <?php 
                        $savedAnswer = isset($_SESSION['quiz_answers'][$currentQuestionIndex]) ? $_SESSION['quiz_answers'][$currentQuestionIndex] : -1;
                        foreach ($currentQuestion['options'] as $optIndex => $option): 
                        ?>
                            <label class="option <?php echo $savedAnswer === $optIndex ? 'selected' : ''; ?>">
                                <input type="radio" name="answer" value="<?php echo $optIndex; ?>" 
                                       <?php echo $savedAnswer === $optIndex ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($option); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="navigation-buttons">
                        <?php if ($currentQuestionIndex < 0): ?>
                            <button type="button" class="btn btn-secondary" id="prevBtn" style="flex: 1; ">
                                ← Previous
                            </button>
                        <?php endif; ?>
                        <button type="submit" class="btn" id="nextBtn" style="flex: 2;">
                            <?php echo ($currentQuestionIndex + 1 >= count($questions)) ? 'Finish Quiz 🏁' : 'Next Question →'; ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Timer functionality
        let timeLeft = <?php echo $TIME_PER_QUESTION; ?>;
        let timerInterval;

        function updateTimer() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            const timeDisplay = document.getElementById('timeDisplay');
            const timer = document.getElementById('timer');
            
            if (timeDisplay) {
                timeDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                
                if (timeLeft <= 20) {
                    timer.classList.add('warning');
                } else {
                    timer.classList.remove('warning');
                }
                
                timeLeft--;
                
                if (timeLeft < 0) {
                    clearInterval(timerInterval);
                    document.getElementById('quizForm').submit();
                }
            }
        }

        if (document.getElementById('timer')) {
            updateTimer();
            timerInterval = setInterval(updateTimer, 1000);
        }

        // Previous button handler
        const prevBtn = document.getElementById('prevBtn');
        if (prevBtn) {
            prevBtn.addEventListener('click', function() {
                document.getElementById('formAction').value = 'prev_question';
                document.getElementById('quizForm').submit();
            });
        }

        // Add interactivity to options
        document.querySelectorAll('.option').forEach(option => {
            option.addEventListener('click', function() {
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;

                this.parentElement.querySelectorAll('.option').forEach(opt => {
                    opt.classList.remove('selected');
                });

                this.classList.add('selected');
            });
        });

        // Update progress bar animation
        const progressBar = document.getElementById('progressBar');
        if (progressBar) {
            setTimeout(() => {
                progressBar.style.transition = 'width 0.5s ease';
            }, 100);
        }
    </script>
</body>
</html>
