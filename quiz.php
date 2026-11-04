<?php

session_start();

// Share one admin session with index.php (Admin Authentication).
if (!empty($_SESSION['admin_authenticated']) || !empty($_SESSION['admin_logged_in'])) {
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_logged_in'] = true;
}

// Configuration
$ADMIN_EMAIL = "Quiztest@fusion.co.th";
$QUIZ_MAPPING_FILE = "quiz_mapping.csv";
$QUIZZES_FOLDER = "quizzes/";
$RESULTS_FILE = "quiz_results.json";
$SECONDS_PER_QUESTION = 60; // default: 1 minute per quiz item
$ADMIN_PASSWORD = "IloveFusion@1234";
$DEFAULT_QUESTIONS_TO_LOAD = 10;
$LOG_FILE = "/var/log/nginx/quiz.log";

function defaultDurationMinutes($questionsCount) {
    global $SECONDS_PER_QUESTION;
    $questionsCount = max(1, (int)$questionsCount);
    return (int)ceil(($questionsCount * $SECONDS_PER_QUESTION) / 60);
}

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

function loadQuizAttempts() {
    global $RESULTS_FILE;
    if (!file_exists($RESULTS_FILE)) {
        return [];
    }
    $raw = @file_get_contents($RESULTS_FILE);
    $data = $raw ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function saveQuizAttempt($attempt) {
    global $RESULTS_FILE;
    $attempts = loadQuizAttempts();
    array_unshift($attempts, $attempt);
    $ok = @file_put_contents(
        $RESULTS_FILE,
        json_encode($attempts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    if ($ok === false) {
        writeLog('ERROR', "Failed to save quiz attempt to $RESULTS_FILE");
        return false;
    }
    writeLog('INFO', "Saved quiz attempt {$attempt['id']} for {$attempt['user_email']}");
    return true;
}

function getClientIp() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    // X-Forwarded-For is client-controlled, so only trust it when the request came through a local proxy.
    $fromLocalProxy = $remote !== '' && !filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($fromLocalProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
            return $forwarded;
        }
    }
    return $remote;
}

function deleteQuizAttempt($id) {
    global $RESULTS_FILE;
    $attempts = loadQuizAttempts();
    $remaining = array_values(array_filter($attempts, function ($a) use ($id) {
        return ($a['id'] ?? '') !== $id;
    }));
    if (count($remaining) === count($attempts)) {
        return false;
    }
    $ok = @file_put_contents(
        $RESULTS_FILE,
        json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    if ($ok === false) {
        writeLog('ERROR', "Failed to delete quiz attempt $id from $RESULTS_FILE");
        return false;
    }
    writeLog('INFO', "Deleted quiz attempt $id");
    return true;
}

function getQuizAttemptById($id) {
    foreach (loadQuizAttempts() as $attempt) {
        if (($attempt['id'] ?? '') === $id) {
            return $attempt;
        }
    }
    return null;
}

function formatTimeSpent($seconds) {
    $seconds = max(0, (int)$seconds);
    $minutes = floor($seconds / 60);
    $secs = $seconds % 60;
    return "$minutes minutes $secs seconds";
}

function renderAnswerReview($questions, $answers = null) {
    $isMasterKey = ($answers === null);

    $html = '<div class="answer-legend">';
    $html .= '<span class="legend-item legend-correct">Correct answer</span>';
    if (!$isMasterKey) {
        $html .= '<span class="legend-item legend-user-wrong">Your incorrect choice</span>';
        $html .= '<span class="legend-item legend-user-correct">Your correct choice</span>';
        $html .= '<span class="legend-item legend-unanswered">Not answered</span>';
    }
    $html .= '</div>';

    foreach ($questions as $index => $question) {
        $userAnswer = (!$isMasterKey && isset($answers[$index]))
            ? (int)$answers[$index]
            : ((!$isMasterKey && isset($answers[(string)$index])) ? (int)$answers[(string)$index] : -1);
        $correct = (int)$question['correct'];
        $isCorrect = ($userAnswer === $correct);
        $answered = (!$isMasterKey && $userAnswer >= 0);

        $cardClass = $isMasterKey ? 'review-ok' : ($isCorrect ? 'review-ok' : 'review-bad');
        $html .= '<div class="review-question ' . $cardClass . '">';
        $html .= '<div class="review-question-header">';
        $html .= '<strong>Question ' . ($index + 1) . '</strong>';
        if ($isMasterKey) {
            $html .= '<span class="review-badge correct">Answer: Option ' . ($correct + 1) . '</span>';
        } elseif (!$answered) {
            $html .= '<span class="review-badge unanswered">Not answered</span>';
        } elseif ($isCorrect) {
            $html .= '<span class="review-badge correct">Correct</span>';
        } else {
            $html .= '<span class="review-badge wrong">Incorrect</span>';
        }
        $html .= '</div>';
        $html .= '<div class="question-text">' . htmlspecialchars($question['question']) . '</div>';

        foreach ($question['options'] as $optIndex => $option) {
            $classes = ['review-option'];
            $labels = [];

            if ($optIndex === $correct) {
                $classes[] = 'is-correct';
                $labels[] = 'Correct answer';
            }
            if ($answered && $optIndex === $userAnswer) {
                if ($optIndex === $correct) {
                    $classes[] = 'is-user-correct';
                    $labels[] = 'Your answer';
                } else {
                    $classes[] = 'is-user-wrong';
                    $labels[] = 'Your answer';
                }
            }

            $html .= '<div class="' . implode(' ', $classes) . '">';
            $html .= '<span class="review-option-text">' . htmlspecialchars($option) . '</span>';
            if (!empty($labels)) {
                $html .= '<span class="review-option-tags">' . htmlspecialchars(implode(' · ', array_unique($labels))) . '</span>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
    }

    return $html;
}

function renderResultsSummaryCard($results) {
    $passed = !empty($results['passed']);
    $html = '<div class="results-card">';
    $html .= '<div class="success-icon">' . ($passed ? '🎉' : '📚') . '</div>';
    $html .= '<h1>' . ($passed ? 'Congratulations!' : 'Keep Learning!') . '</h1>';
    $html .= '<h2 style="text-align: center; color: #666; margin-bottom: 20px;">' . htmlspecialchars($results['quiz_title']) . '</h2>';
    $html .= '<div class="status-badge ' . ($passed ? 'status-passed' : 'status-failed') . '">';
    $html .= htmlspecialchars($results['status']) . ' ' . ($passed ? '✓' : '✗');
    $html .= '</div>';
    $html .= '<div class="score-display">' . (int)$results['score'] . '/' . (int)$results['total'] . '</div>';
    $html .= '<div class="result-info">';
    $html .= '<p><strong>Percentage:</strong> ' . htmlspecialchars((string)$results['percentage']) . '%</p>';
    $html .= '<p><strong>Passing Score Required:</strong> ' . (int)$results['passing_score'] . '%</p>';
    $html .= '<p><strong>Time Spent:</strong> ' . htmlspecialchars($results['time_spent']) . '</p>';
    if (!empty($results['timed_out'])) {
        $html .= '<p><strong>Note:</strong> Quiz ended because time ran out.</p>';
    }
    if (isset($results['leave_count'])) {
        $html .= '<p><strong>Left page:</strong> ' . (int)$results['leave_count'] . '</p>';
    }
    if (!empty($results['user_name'])) {
        $html .= '<p><strong>Candidate:</strong> ' . htmlspecialchars($results['user_name']);
        if (!empty($results['user_email'])) {
            $html .= ' (' . htmlspecialchars($results['user_email']) . ')';
        }
        $html .= '</p>';
    }
    $html .= '</div></div>';
    return $html;
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
                $questionsCount = (int)$data[2];
                $durationMinutes = isset($data[4]) && $data[4] !== ''
                    ? (int)$data[4]
                    : defaultDurationMinutes($questionsCount);
                if ($durationMinutes <= 0) {
                    $durationMinutes = defaultDurationMinutes($questionsCount);
                }
                $quizzes[] = [
                    "title" => $data[0],
                    "filename" => $data[1],
                    "questions_count" => $questionsCount,
                    "passing_score" => (int)$data[3],
                    "duration_minutes" => $durationMinutes
                ];
                writeLog('DEBUG', "Loaded quiz from line $lineNum: Title='{$data[0]}', File='{$data[1]}', Count={$questionsCount}, PassingScore={$data[3]}%, Duration={$durationMinutes}m");
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
        saveQuizMapping($mappingFile, $title, $filename, $defaultCount, 70, defaultDurationMinutes($defaultCount));
        $newQuizzesAdded++;
    }
    
    if ($newQuizzesAdded > 0) {
        writeLog('INFO', "Auto-discovered and added $newQuizzesAdded new quizzes");
    } else {
        writeLog('DEBUG', "No new quizzes to add");
    }
}

// Function to write all quiz mappings (overwrite)
function writeAllQuizMappings($filename, $quizzes) {
    $handle = fopen($filename, "w");
    if ($handle === FALSE) {
        writeLog('ERROR', "Failed to open quiz mapping file for writing: $filename");
        return false;
    }
    
    fputcsv($handle, ["Quiz Title", "CSV Filename", "Questions to Load", "Passing Score (%)", "Duration (minutes)"]);
    foreach ($quizzes as $quiz) {
        fputcsv($handle, [
            $quiz['title'],
            $quiz['filename'],
            $quiz['questions_count'],
            $quiz['passing_score'],
            $quiz['duration_minutes']
        ]);
    }
    fclose($handle);
    writeLog('INFO', "Wrote " . count($quizzes) . " quiz mappings to $filename");
    return true;
}

// Function to save quiz mapping (append)
function saveQuizMapping($filename, $title, $csvFilename, $questionsCount, $passingScore = 70, $durationMinutes = null) {
    if ($durationMinutes === null || $durationMinutes <= 0) {
        $durationMinutes = defaultDurationMinutes($questionsCount);
    }
    
    writeLog('DEBUG', "Saving quiz mapping: Title='$title', File='$csvFilename', Count=$questionsCount, PassingScore=$passingScore%, Duration={$durationMinutes}m");
    
    $quizzes = loadQuizMapping($filename);
    $quizzes[] = [
        'title' => $title,
        'filename' => $csvFilename,
        'questions_count' => (int)$questionsCount,
        'passing_score' => (int)$passingScore,
        'duration_minutes' => (int)$durationMinutes
    ];
    
    if (writeAllQuizMappings($filename, $quizzes)) {
        writeLog('INFO', "Quiz mapping saved successfully");
        return true;
    }
    return false;
}

// Validate and normalize quiz rows; returns [questions, errors]
function normalizeQuizRows($rows) {
    $questions = [];
    $errors = [];
    foreach ($rows as $i => $row) {
        $label = 'Question ' . ($i + 1);
        $question = trim((string)($row['question'] ?? ''));
        $options = array_map(function ($o) { return trim((string)$o); }, $row['options'] ?? []);
        $correct = $row['correct'] ?? '';

        if ($question === '') {
            $errors[] = "$label: question text is empty.";
            continue;
        }
        if (count($options) !== 4 || in_array('', $options, true)) {
            $errors[] = "$label: all 4 options are required.";
            continue;
        }
        if (!is_numeric($correct) || (int)$correct < 0 || (int)$correct > 3) {
            $errors[] = "$label: correct_index must be 0-3.";
            continue;
        }
        $questions[] = ['question' => $question, 'options' => $options, 'correct' => (int)$correct];
    }
    if (empty($questions) && empty($errors)) {
        $errors[] = "The quiz must contain at least one question.";
    }
    return [$questions, $errors];
}

function parseQuizCsvFile($path) {
    $rows = [];
    $handle = fopen($path, 'r');
    if ($handle === FALSE) {
        return [[], ["Cannot read uploaded file."]];
    }
    fgetcsv($handle);
    while (($data = fgetcsv($handle)) !== FALSE) {
        if (count($data) === 1 && trim((string)$data[0]) === '') {
            continue;
        }
        $rows[] = [
            'question' => $data[0] ?? '',
            'options' => [$data[1] ?? '', $data[2] ?? '', $data[3] ?? '', $data[4] ?? ''],
            'correct' => $data[5] ?? ''
        ];
    }
    fclose($handle);
    return normalizeQuizRows($rows);
}

function backupQuizFile($path) {
    if (!file_exists($path)) {
        return;
    }
    $backupDir = __DIR__ . '/tmp/quiz_backups/';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0775, true);
    }
    @copy($path, $backupDir . basename($path) . '.' . date('Ymd_His') . '.bak');
}

function writeQuestionsToCSV($path, $questions) {
    $handle = fopen($path, 'w');
    if ($handle === FALSE) {
        writeLog('ERROR', "Failed to open quiz file for writing: $path");
        return false;
    }
    fputcsv($handle, ['question', 'option1', 'option2', 'option3', 'option4', 'correct_index']);
    foreach ($questions as $q) {
        fputcsv($handle, [$q['question'], $q['options'][0], $q['options'][1], $q['options'][2], $q['options'][3], $q['correct']]);
    }
    fclose($handle);
    writeLog('INFO', "Wrote " . count($questions) . " questions to $path");
    return true;
}

// Function to load questions from CSV (optionally shuffle and limit)
function loadQuestionsFromCSV($filename, $questionsToLoad = null, $shuffle = true) {
    $questions = [];
    
    writeLog('DEBUG', "Loading questions from: $filename (limit: $questionsToLoad, shuffle: " . ($shuffle ? 'yes' : 'no') . ")");
    
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
    
    if ($shuffle) {
        shuffle($questions);
        writeLog('DEBUG', "Questions shuffled randomly");
    }
    
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
            $_SESSION['admin_authenticated'] = true;
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
                $durationMinutes = isset($_POST['duration_minutes']) && $_POST['duration_minutes'] !== ''
                    ? (int)$_POST['duration_minutes']
                    : defaultDurationMinutes($questionsToLoad);
                
                writeLog('DEBUG', "Upload details - Title: '$quizTitle', Questions: $questionsToLoad, PassingScore: $passingScore%, Duration: {$durationMinutes}m");
                
                if (empty($quizTitle)) {
                    $upload_error = "Please enter a quiz title.";
                    writeLog('ERROR', "Quiz upload failed - no title provided");
                } elseif ($questionsToLoad <= 0) {
                    $upload_error = "Please enter a valid number of questions to load.";
                    writeLog('ERROR', "Quiz upload failed - invalid questions count: $questionsToLoad");
                } elseif ($passingScore <= 0 || $passingScore > 100) {
                    $upload_error = "Please enter a valid passing score between 1 and 100.";
                    writeLog('ERROR', "Quiz upload failed - invalid passing score: $passingScore");
                } elseif ($durationMinutes <= 0) {
                    $upload_error = "Please enter a valid total duration (minutes).";
                    writeLog('ERROR', "Quiz upload failed - invalid duration: $durationMinutes");
                } else {
                    $fileTmpPath = $_FILES['csv_file']['tmp_name'];
                    $fileName = $_FILES['csv_file']['name'];
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    
                    if ($fileExtension === 'csv') {
                        $newFilename = preg_replace('/[^a-z0-9_-]/i', '_', $quizTitle) . '_' . time() . '.csv';
                        $destination = $QUIZZES_FOLDER . $newFilename;
                        
                        writeLog('DEBUG', "Moving uploaded file to: $destination");
                        
                        if (move_uploaded_file($fileTmpPath, $destination)) {
                            saveQuizMapping($QUIZ_MAPPING_FILE, $quizTitle, $newFilename, $questionsToLoad, $passingScore, $durationMinutes);
                            $upload_success = "Quiz '$quizTitle' uploaded successfully! Total duration: {$durationMinutes} minute(s).";
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
    } elseif ($_POST['admin_action'] === 'update_quiz') {
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
            $quizIndex = (int)($_POST['quiz_index'] ?? -1);
            $quizTitle = trim($_POST['quiz_title'] ?? '');
            $questionsToLoad = (int)($_POST['questions_to_load'] ?? 0);
            $passingScore = (int)($_POST['passing_score'] ?? 0);
            $durationMinutes = (int)($_POST['duration_minutes'] ?? 0);
            
            writeLog('INFO', "Admin updating quiz index $quizIndex");
            
            $quizzes = loadQuizMapping($QUIZ_MAPPING_FILE);
            if (!isset($quizzes[$quizIndex])) {
                $upload_error = "Quiz not found.";
                writeLog('ERROR', "Quiz update failed - invalid index: $quizIndex");
            } elseif (empty($quizTitle)) {
                $upload_error = "Please enter a quiz title.";
            } elseif ($questionsToLoad <= 0) {
                $upload_error = "Please enter a valid number of questions to load.";
            } elseif ($passingScore <= 0 || $passingScore > 100) {
                $upload_error = "Please enter a valid passing score between 1 and 100.";
            } elseif ($durationMinutes <= 0) {
                $upload_error = "Please enter a valid total duration (minutes).";
            } else {
                $quizzes[$quizIndex]['title'] = $quizTitle;
                $quizzes[$quizIndex]['questions_count'] = $questionsToLoad;
                $quizzes[$quizIndex]['passing_score'] = $passingScore;
                $quizzes[$quizIndex]['duration_minutes'] = $durationMinutes;
                
                if (writeAllQuizMappings($QUIZ_MAPPING_FILE, $quizzes)) {
                    $upload_success = "Quiz '$quizTitle' parameters updated successfully.";
                    writeLog('INFO', "Quiz updated: index=$quizIndex, title='$quizTitle', questions=$questionsToLoad, pass=$passingScore%, duration={$durationMinutes}m");
                } else {
                    $upload_error = "Failed to save quiz parameters.";
                }
            }
        }
    } elseif ($_POST['admin_action'] === 'replace_csv') {
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
            $quizIndex = (int)($_POST['quiz_index'] ?? -1);
            $quizzes = loadQuizMapping($QUIZ_MAPPING_FILE);
            
            if (!isset($quizzes[$quizIndex])) {
                $upload_error = "Quiz not found.";
            } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $upload_error = "Error uploading file.";
            } elseif (strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION)) !== 'csv') {
                $upload_error = "Please upload a CSV file.";
            } else {
                [$newQuestions, $errors] = parseQuizCsvFile($_FILES['csv_file']['tmp_name']);
                $quizTitle = $quizzes[$quizIndex]['title'];
                if (!empty($errors)) {
                    $upload_error = "CSV for '$quizTitle' was not updated: " . implode(' ', array_slice($errors, 0, 5));
                    writeLog('ERROR', "Replace CSV rejected for '$quizTitle': " . implode(' | ', $errors));
                } else {
                    $destination = $QUIZZES_FOLDER . basename($quizzes[$quizIndex]['filename']);
                    backupQuizFile($destination);
                    if (writeQuestionsToCSV($destination, $newQuestions)) {
                        $upload_success = "CSV for '$quizTitle' updated (" . count($newQuestions) . " questions).";
                        writeLog('INFO', "Admin replaced CSV for '$quizTitle' with " . count($newQuestions) . " questions");
                    } else {
                        $upload_error = "Failed to write CSV for '$quizTitle'.";
                    }
                }
            }
        }
    } elseif ($_POST['admin_action'] === 'save_quiz_questions') {
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
            $quizIndex = (int)($_POST['quiz_index'] ?? -1);
            $quizzes = loadQuizMapping($QUIZ_MAPPING_FILE);
            
            $rows = [];
            foreach (($_POST['questions'] ?? []) as $row) {
                if (!empty($row['delete'])) {
                    continue;
                }
                $rows[] = [
                    'question' => $row['question'] ?? '',
                    'options' => [$row['o0'] ?? '', $row['o1'] ?? '', $row['o2'] ?? '', $row['o3'] ?? ''],
                    'correct' => $row['correct'] ?? ''
                ];
            }
            
            if (!isset($quizzes[$quizIndex])) {
                $edit_error = "Quiz not found.";
            } else {
                [$editedQuestions, $errors] = normalizeQuizRows($rows);
                if (!empty($errors)) {
                    $edit_error = implode(' ', $errors);
                    $editPostedRows = $rows;
                } else {
                    $destination = $QUIZZES_FOLDER . basename($quizzes[$quizIndex]['filename']);
                    backupQuizFile($destination);
                    if (writeQuestionsToCSV($destination, $editedQuestions)) {
                        writeLog('INFO', "Admin edited questions for '{$quizzes[$quizIndex]['title']}' (" . count($editedQuestions) . " questions)");
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?admin=1&quiz_edit=' . $quizIndex . '&saved=' . count($editedQuestions));
                        exit;
                    }
                    $edit_error = "Failed to write the quiz CSV file.";
                    $editPostedRows = $rows;
                }
            }
        }
    } elseif ($_POST['admin_action'] === 'move_quiz_up') {
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
            $quizIndex = (int)($_POST['quiz_index'] ?? -1);
            $quizzes = loadQuizMapping($QUIZ_MAPPING_FILE);
            if (!isset($quizzes[$quizIndex])) {
                $upload_error = "Quiz not found.";
            } elseif ($quizIndex === 0) {
                $upload_error = "บนสุดแล้ว เลื่อนไม่ได้";
            } else {
                $previous = $quizzes[$quizIndex - 1];
                $quizzes[$quizIndex - 1] = $quizzes[$quizIndex];
                $quizzes[$quizIndex] = $previous;
                if (writeAllQuizMappings($QUIZ_MAPPING_FILE, $quizzes)) {
                    $movedTitle = $quizzes[$quizIndex - 1]['title'];
                    $upload_success = "Moved '$movedTitle' up.";
                    writeLog('INFO', "Admin moved quiz up: index $quizIndex -> " . ($quizIndex - 1) . " ($movedTitle)");
                } else {
                    $upload_error = "Failed to save quiz order.";
                }
            }
        }
    } elseif ($_POST['admin_action'] === 'delete_attempt') {
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
            $attemptId = (string)($_POST['attempt_id'] ?? '');
            $attempt = getQuizAttemptById($attemptId);
            if ($attempt && deleteQuizAttempt($attemptId)) {
                $upload_success = "Deleted result of " . ($attempt['user_name'] ?? '-') . " (" . ($attempt['quiz_title'] ?? '-') . ").";
            } else {
                $upload_error = "Result not found or could not be deleted.";
            }
        }
    } elseif ($_POST['admin_action'] === 'logout') {
        writeLog('INFO', "Admin logged out");
        unset($_SESSION['admin_logged_in'], $_SESSION['admin_authenticated']);
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
        $_SESSION['quiz_duration_seconds'] = max(60, (int)$selectedQuiz['duration_minutes'] * 60);
        $_SESSION['leave_count'] = 0;
        
        writeLog('INFO', "Quiz loaded with " . count($_SESSION['quiz_questions']) . " questions, duration={$selectedQuiz['duration_minutes']}m");
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
    unset($_SESSION['quiz_duration_seconds']);
    unset($_SESSION['quiz_results']);
    unset($_SESSION['leave_count']);
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['next_question', 'prev_question', 'finish_quiz'], true)) {
    $currentIndex = (int)$_POST['current_index'];
    $answer = isset($_POST["answer"]) ? (int)$_POST["answer"] : -1;
    
    writeLog('DEBUG', "Navigation: action={$_POST['action']}, question=$currentIndex, answer=$answer");

    $postedLeaveCount = max(0, (int)($_POST['leave_count'] ?? 0));
    $_SESSION['leave_count'] = max((int)($_SESSION['leave_count'] ?? 0), $postedLeaveCount);
    
    if (!isset($_SESSION['quiz_answers'])) {
        $_SESSION['quiz_answers'] = [];
    }
    $_SESSION['quiz_answers'][$currentIndex] = $answer;

    // Enforce total quiz duration on the server
    $durationSeconds = isset($_SESSION['quiz_duration_seconds']) ? (int)$_SESSION['quiz_duration_seconds'] : 0;
    $startTime = isset($_SESSION['start_time']) ? (int)$_SESSION['start_time'] : time();
    $timeExpired = $durationSeconds > 0 && (time() - $startTime) >= $durationSeconds;
    
    if ($_POST['action'] === 'finish_quiz' || $timeExpired) {
        $_SESSION['current_question'] = count($questions);
        writeLog('INFO', $timeExpired ? "Quiz time expired - forcing finish" : "User finished quiz via timer/submit");
    } elseif ($_POST['action'] === 'next_question') {
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
        $normalizedAnswers = [];
        
        foreach ($questions as $index => $question) {
            $userAnswer = isset($_SESSION['quiz_answers'][$index]) ? (int)$_SESSION['quiz_answers'][$index] : -1;
            $normalizedAnswers[$index] = $userAnswer;
            if ($userAnswer === (int)$question['correct']) {
                $score++;
            }
        }
        
        $percentage = $total > 0 ? round(($score / $total) * 100, 2) : 0;
        $timeSpentSeconds = time() - (int)$_SESSION['start_time'];
        $passingScore = (int)$selectedQuiz['passing_score'];
        $passed = $percentage >= $passingScore;
        $status = $passed ? "PASSED" : "FAILED";
        $userEmail = $_SESSION['user_email'] ?? '';
        $userName = $_SESSION['user_name'] ?? '';
        
        $attempt = [
            'id' => uniqid('attempt_', true),
            'completed_at' => date('Y-m-d H:i:s'),
            'ip_address' => getClientIp(),
            'user_name' => $userName,
            'user_email' => $userEmail,
            'quiz_title' => $selectedQuiz['title'],
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
            'passing_score' => $passingScore,
            'passed' => $passed,
            'status' => $status,
            'time_spent_seconds' => $timeSpentSeconds,
            'time_spent' => formatTimeSpent($timeSpentSeconds),
            'timed_out' => !empty($timeExpired),
            'leave_count' => (int)($_SESSION['leave_count'] ?? 0),
            'answers' => $normalizedAnswers,
            'questions' => $questions
        ];
        
        saveQuizAttempt($attempt);
        $_SESSION['quiz_results'] = $attempt;
        
        writeLog('INFO', "Quiz completed by $userName ($userEmail): Score=$score/$total ($percentage%), Status=$status, Time={$attempt['time_spent']}");
        
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
$adminLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

if ($showAdminPanel && $adminLoggedIn && isset($_GET['download_quiz'])) {
    $downloadIndex = (int)$_GET['download_quiz'];
    $downloadQuizzes = loadQuizMapping($QUIZ_MAPPING_FILE);
    $downloadName = isset($downloadQuizzes[$downloadIndex]) ? basename($downloadQuizzes[$downloadIndex]['filename']) : '';
    $quizzesRoot = realpath($QUIZZES_FOLDER);
    $downloadPath = ($downloadName !== '' && preg_match('/\.csv$/i', $downloadName)) ? realpath($QUIZZES_FOLDER . $downloadName) : false;
    $insideQuizzes = $downloadPath && $quizzesRoot && strpos($downloadPath, $quizzesRoot . DIRECTORY_SEPARATOR) === 0 && is_file($downloadPath);
    if (!$insideQuizzes) {
        http_response_code(404);
        exit('Quiz file not found.');
    }
    writeLog('INFO', "Admin downloaded quiz CSV: $downloadName");
    $csvBody = file_get_contents($downloadPath);
    if (strncmp($csvBody, "\xEF\xBB\xBF", 3) !== 0) {
        $csvBody = "\xEF\xBB\xBF" . $csvBody;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . strlen($csvBody));
    header('X-Content-Type-Options: nosniff');
    echo $csvBody;
    exit;
}

$adminReviewAttempt = null;
if ($showAdminPanel && $adminLoggedIn && isset($_GET['review'])) {
    $adminReviewAttempt = getQuizAttemptById($_GET['review']);
}
$adminQuizKey = null;
$adminQuizKeyQuestions = [];
if ($showAdminPanel && $adminLoggedIn && isset($_GET['quiz_key'])) {
    $quizKeyIndex = (int)$_GET['quiz_key'];
    if (isset($availableQuizzes[$quizKeyIndex])) {
        $adminQuizKey = $availableQuizzes[$quizKeyIndex];
        $adminQuizKeyQuestions = loadQuestionsFromCSV($QUIZZES_FOLDER . $adminQuizKey['filename'], null, false);
    }
}
$adminEditQuiz = null;
$adminEditIndex = null;
$adminEditRows = [];
if ($showAdminPanel && $adminLoggedIn && isset($_GET['quiz_edit'])) {
    $adminEditIndex = (int)$_GET['quiz_edit'];
    if (isset($availableQuizzes[$adminEditIndex])) {
        $adminEditQuiz = $availableQuizzes[$adminEditIndex];
        $adminEditRows = isset($editPostedRows)
            ? $editPostedRows
            : loadQuestionsFromCSV($QUIZZES_FOLDER . $adminEditQuiz['filename'], null, false);
    }
}
$quizAttempts = ($showAdminPanel && $adminLoggedIn) ? loadQuizAttempts() : [];

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
            min-width: 0;
            padding: 40px;
            animation: slideUp 0.5s ease;
        }

        .container.admin-dashboard {
            max-width: 1180px;
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

        .btn-sm {
            width: auto;
            display: inline-block;
            padding: 8px 14px;
            font-size: 0.9em;
        }

        .answer-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0;
        }

        .legend-item {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .legend-correct { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .legend-user-wrong { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .legend-user-correct { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        .legend-unanswered { background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db; }

        .review-question {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 16px;
        }

        .review-question.review-ok { border-left: 5px solid #28a745; }
        .review-question.review-bad { border-left: 5px solid #dc3545; }

        .review-question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .review-badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.8em;
            font-weight: 700;
        }

        .review-badge.correct { background: #d4edda; color: #155724; }
        .review-badge.wrong { background: #f8d7da; color: #721c24; }
        .review-badge.unanswered { background: #e2e3e5; color: #383d41; }

        .review-option {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            padding: 12px 14px;
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            margin-bottom: 8px;
            background: #fafafa;
        }

        .review-option.is-correct {
            background: #d4edda;
            border-color: #28a745;
        }

        .review-option.is-user-wrong {
            background: #f8d7da;
            border-color: #dc3545;
        }

        .review-option.is-user-correct {
            background: #cce5ff;
            border-color: #007bff;
        }

        .review-option-text { flex: 1; }
        .review-option-tags {
            font-size: 0.8em;
            font-weight: 700;
            white-space: nowrap;
        }

        .edit-question {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-left: 5px solid #667eea;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 16px;
        }

        .edit-question.is-deleted {
            opacity: 0.45;
            border-left-color: #dc3545;
        }

        #editQuestionsForm,
        #editQuestionsForm textarea,
        #editQuestionsForm input {
            -webkit-user-select: text;
            user-select: text;
        }

        .edit-question textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 15px;
            margin-bottom: 10px;
            resize: vertical;
        }

        .edit-option {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .edit-option input[type="radio"] {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .edit-option input[type="text"] {
            flex: 1;
            padding: 10px;
            font-size: 14px;
        }

        .edit-delete {
            color: #dc3545;
            font-size: 0.9em;
            cursor: pointer;
        }

        .admin-section-title {
            margin-top: 40px;
            margin-bottom: 10px;
        }

        .no-copy {
            -webkit-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
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

        .pdpa-page {
            position: relative;
        }

        .pdpa-lang {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            margin-bottom: 8px;
        }

        .pdpa-lang .lang-btn {
            border: 1px solid #667eea;
            background: transparent;
            color: #667eea;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            padding: 3px 8px;
            line-height: 1.4;
            cursor: pointer;
        }

        .pdpa-lang .lang-btn.is-active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border-color: transparent;
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
            max-width: 100%;
        }

        .admin-table-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            margin-top: 12px;
        }

        .admin-panel table {
            width: 100%;
            margin-top: 0;
            border-collapse: collapse;
        }

        .admin-panel table.results-table {
            min-width: 860px;
        }

        .admin-panel table.results-table th,
        .admin-panel table.results-table td {
            padding: 10px 8px;
            vertical-align: top;
            font-size: 0.92em;
        }

        .results-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 132px;
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

        .quiz-briefing {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(45, 27, 105, 0.55);
            backdrop-filter: blur(6px);
        }

        .quiz-briefing[hidden] {
            display: none;
        }

        .quiz-briefing-card {
            width: min(520px, 100%);
            max-height: calc(100vh - 40px);
            overflow: auto;
            background: #fff;
            border-radius: 22px;
            box-shadow: 0 24px 60px rgba(45, 27, 105, 0.35);
            animation: slideUp 0.35s ease;
        }

        .quiz-briefing-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 28px 28px 22px;
        }

        .quiz-briefing-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 6px;
        }

        .quiz-briefing-lang {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }

        .lang-btn {
            border: 1px solid rgba(255, 255, 255, 0.75);
            background: transparent;
            color: #fff;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            padding: 3px 8px;
            line-height: 1.4;
            cursor: pointer;
        }

        .lang-btn.is-active {
            background: #fff;
            color: #5b4bdb;
            border-color: #fff;
        }

        .quiz-briefing-kicker {
            font-size: 0.85em;
            letter-spacing: 0.04em;
            opacity: 0.9;
            margin-bottom: 0;
        }

        .quiz-briefing-hero h2 {
            color: #fff;
            text-align: left;
            font-size: 1.55em;
            margin: 0 0 6px;
        }

        .quiz-briefing-hero p {
            opacity: 0.92;
            margin: 0;
        }

        .quiz-briefing-body {
            padding: 22px 28px 26px;
        }

        .quiz-briefing-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 18px;
        }

        .quiz-briefing-stat {
            background: #f4f1ff;
            border: 1px solid #e3dcff;
            border-radius: 14px;
            padding: 12px 14px;
        }

        .quiz-briefing-stat strong {
            display: block;
            color: #5b4bdb;
            font-size: 1.15em;
        }

        .quiz-briefing-stat span {
            color: #666;
            font-size: 0.85em;
        }

        .quiz-briefing-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 18px;
        }

        .quiz-briefing-list li {
            background: #f8f9fc;
            border-radius: 12px;
            padding: 10px 12px;
            color: #333;
            line-height: 1.45;
        }

        .quiz-briefing-list li.caution {
            background: #f7f1ff;
            color: #4c3478;
        }

        .quiz-briefing-actions {
            display: flex;
            gap: 10px;
        }

        .quiz-briefing-actions .btn {
            margin-top: 0;
        }

        .quiz-briefing-actions .btn-outline {
            width: 38%;
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
    <div class="container<?php echo ($showAdminPanel && $adminLoggedIn && !isset($_GET['review']) && !isset($_GET['quiz_edit']) && !isset($_GET['quiz_key'])) ? ' admin-dashboard' : ''; ?>">
        <?php if (!isset($_SESSION['pdpa_accepted'])): ?>
            <!-- PDPA Consent Page -->
            <div class="pdpa-page" id="pdpaPage">
            <div class="pdpa-lang">
                <button type="button" class="lang-btn is-active" data-lang="th">TH</button>
                <button type="button" class="lang-btn" data-lang="en">EN</button>
            </div>
            <h1 data-i18n="title"></h1>
            <p class="subtitle" data-i18n="subtitle"></p>

            <div class="pdpa-box">
                <h3 data-i18n="collectTitle"></h3>
                <p data-i18n="intro"></p>

                <h3 data-i18n="itemsTitle"></h3>
                <ul>
                    <li data-i18n="item1"></li>
                    <li data-i18n="item2"></li>
                    <li data-i18n="item3"></li>
                    <li data-i18n="item4"></li>
                    <li data-i18n="item5"></li>
                    <li data-i18n="item6"></li>
                    <li data-i18n="item7"></li>
                </ul>

                <h3 data-i18n="useTitle"></h3>
                <ul>
                    <li data-i18n="use1"></li>
                    <li data-i18n="use2"></li>
                    <li data-i18n="use3"></li>
                    <li data-i18n="use4"></li>
                    <li data-i18n="use5"></li>
                </ul>

                <h3 data-i18n="protectTitle"></h3>
                <p data-i18n="protect"></p>

                <p style="margin-top: 20px;">
                    <strong data-i18n="consent"></strong>
                </p>
            </div>

            <form method="POST">
                <button type="submit" name="accept_pdpa" class="btn" data-i18n="accept"></button>
            </form>
            <script>
                (function() {
                    const page = document.getElementById('pdpaPage');
                    let lang = 'th';
                    const copy = {
                        th: {
                            title: '🔒 ประกาศความเป็นส่วนตัว',
                            subtitle: 'ความยินยอมตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล (PDPA)',
                            collectTitle: 'การเก็บรวบรวมข้อมูลส่วนบุคคล',
                            intro: 'ก่อนเริ่มทำข้อสอบ เราขอแจ้งให้ทราบว่ามีการเก็บและใช้ข้อมูลส่วนบุคคลของท่านอย่างไร',
                            itemsTitle: 'ข้อมูลที่เราเก็บ',
                            item1: 'ชื่อ',
                            item2: 'อีเมล',
                            item3: 'ที่อยู่ IP ของอุปกรณ์ที่ใช้ทำข้อสอบ',
                            item4: 'รูปถ่ายของผู้เข้าสอบ',
                            item5: 'ผลการทำข้อสอบและคะแนน',
                            item6: 'เวลาที่ใช้ทำข้อสอบ',
                            item7: 'คำตอบของข้อสอบ',
                            useTitle: 'เราใช้ข้อมูลอย่างไร',
                            use1: 'เพื่อแสดงคะแนนและเฉลยหลังทำข้อสอบ',
                            use2: 'เพื่อยืนยันตัวตนของผู้เข้าสอบและรักษาความถูกต้องของข้อสอบ',
                            use3: 'เพื่อเก็บบันทึกการส่งข้อสอบ',
                            use4: 'เพื่อปรับปรุงเนื้อหาข้อสอบและประสบการณ์ผู้ใช้',
                            use5: 'เพื่อการบริหารและการติดต่อสื่อสาร',
                            protectTitle: 'การคุ้มครองข้อมูล',
                            protect: 'ข้อมูลส่วนบุคคลจะถูกจัดเก็บอย่างปลอดภัย และจะไม่เปิดเผยแก่บุคคลภายนอกหากไม่ได้รับความยินยอม เรามุ่งมั่นคุ้มครองความเป็นส่วนตัวตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล',
                            consent: 'เมื่อกด "ยอมรับและดำเนินการต่อ" ถือว่าท่านยินยอมให้เก็บและใช้ข้อมูลส่วนบุคคลตามที่แจ้งไว้ข้างต้น',
                            accept: '✓ ยอมรับและดำเนินการต่อ'
                        },
                        en: {
                            title: '🔒 Privacy Notice',
                            subtitle: 'Personal Data Protection Act (PDPA) Consent',
                            collectTitle: 'Collection of Personal Information',
                            intro: 'Before you proceed with the quiz, we would like to inform you about how we collect and use your personal information.',
                            itemsTitle: 'What Information We Collect:',
                            item1: 'Name',
                            item2: 'Email address',
                            item3: 'IP address of the device used to take the quiz',
                            item4: 'Photographs of the person taking the quiz',
                            item5: 'Quiz results and scores',
                            item6: 'Time spent on the quiz',
                            item7: 'Answers to quiz questions',
                            useTitle: 'How We Use Your Information:',
                            use1: 'To show your quiz score and answer key after the test',
                            use2: 'To verify the identity of the person taking the quiz and support quiz integrity',
                            use3: 'To maintain records of quiz submissions',
                            use4: 'To improve our quiz content and user experience',
                            use5: 'For administrative purposes and communication',
                            protectTitle: 'Data Protection:',
                            protect: 'Your personal information will be stored securely and will not be shared with third parties without your consent. We are committed to protecting your privacy in accordance with the Personal Data Protection Act.',
                            consent: 'By clicking "Accept and Continue", you consent to the collection and use of your personal information as described above.',
                            accept: '✓ Accept and Continue'
                        }
                    };

                    function applyLanguage() {
                        const text = copy[lang];
                        page.querySelectorAll('[data-i18n]').forEach(function(el) {
                            const key = el.getAttribute('data-i18n');
                            if (text[key]) el.textContent = text[key];
                        });
                        page.querySelectorAll('.lang-btn').forEach(function(btn) {
                            const active = btn.getAttribute('data-lang') === lang;
                            btn.classList.toggle('is-active', active);
                            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                        });
                    }

                    page.querySelectorAll('.lang-btn').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            lang = btn.getAttribute('data-lang') === 'en' ? 'en' : 'th';
                            applyLanguage();
                        });
                    });

                    applyLanguage();
                })();
            </script>
            </div>

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

            <?php elseif (isset($_GET['review'])): ?>
                <!-- Admin Answer Key Review -->
                <?php if ($adminReviewAttempt): ?>
                    <h1>📋 Answer Key Review</h1>
                    <p class="subtitle">Attempt ID: <?php echo htmlspecialchars($adminReviewAttempt['id']); ?></p>
                    <?php echo renderResultsSummaryCard($adminReviewAttempt); ?>
                    <h2 style="margin: 30px 0 10px;">Full Answer Key</h2>
                    <?php echo renderAnswerReview($adminReviewAttempt['questions'] ?? [], $adminReviewAttempt['answers'] ?? []); ?>
                <?php else: ?>
                    <h1>📋 Answer Key Review</h1>
                    <div class="error">Attempt not found.</div>
                <?php endif; ?>
                <div style="margin-top: 30px;">
                    <a href="?admin=1" style="text-decoration: none;">
                        <button type="button" class="btn btn-secondary">← Back to Admin Dashboard</button>
                    </a>
                </div>

            <?php elseif (isset($_GET['quiz_edit'])): ?>
                <!-- Admin Quiz Question Editor -->
                <?php if ($adminEditQuiz): ?>
                    <h1>✏️ Edit Questions: <?php echo htmlspecialchars($adminEditQuiz['title']); ?></h1>
                    <p class="subtitle">File: <?php echo htmlspecialchars($adminEditQuiz['filename']); ?> · Select the radio button next to the correct answer.</p>

                    <?php if (isset($_GET['saved'])): ?>
                        <div class="success">Saved <?php echo (int)$_GET['saved']; ?> questions.</div>
                    <?php endif; ?>
                    <?php if (isset($edit_error)): ?>
                        <div class="error">Not saved: <?php echo htmlspecialchars($edit_error); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="?admin=1&quiz_edit=<?php echo (int)$adminEditIndex; ?>" id="editQuestionsForm">
                        <input type="hidden" name="admin_action" value="save_quiz_questions">
                        <input type="hidden" name="quiz_index" value="<?php echo (int)$adminEditIndex; ?>">

                        <div id="questionList">
                            <?php foreach ($adminEditRows as $qi => $q): ?>
                                <div class="edit-question">
                                    <div class="review-question-header">
                                        <strong class="edit-question-number">Question <?php echo $qi + 1; ?></strong>
                                        <div style="display: flex; gap: 12px; align-items: center;">
                                            <button type="button" class="btn btn-secondary btn-sm copy-question-btn">Copy</button>
                                            <label class="edit-delete"><input type="checkbox" name="questions[<?php echo $qi; ?>][delete]" value="1"> Delete</label>
                                        </div>
                                    </div>
                                    <textarea name="questions[<?php echo $qi; ?>][question]" rows="2" required><?php echo htmlspecialchars($q['question']); ?></textarea>
                                    <?php for ($oi = 0; $oi < 4; $oi++): ?>
                                        <div class="edit-option">
                                            <input type="radio" name="questions[<?php echo $qi; ?>][correct]" value="<?php echo $oi; ?>" <?php echo ((string)$q['correct'] === (string)$oi) ? 'checked' : ''; ?> required title="Correct answer">
                                            <input type="text" name="questions[<?php echo $qi; ?>][o<?php echo $oi; ?>]" value="<?php echo htmlspecialchars($q['options'][$oi] ?? ''); ?>" placeholder="Option <?php echo $oi + 1; ?>" required>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <template id="questionTemplate">
                            <div class="edit-question">
                                <div class="review-question-header">
                                    <strong class="edit-question-number">New question</strong>
                                    <div style="display: flex; gap: 12px; align-items: center;">
                                        <button type="button" class="btn btn-secondary btn-sm copy-question-btn">Copy</button>
                                        <label class="edit-delete"><input type="checkbox" name="questions[__I__][delete]" value="1"> Delete</label>
                                    </div>
                                </div>
                                <textarea name="questions[__I__][question]" rows="2" required></textarea>
                                <?php for ($oi = 0; $oi < 4; $oi++): ?>
                                    <div class="edit-option">
                                        <input type="radio" name="questions[__I__][correct]" value="<?php echo $oi; ?>" required title="Correct answer">
                                        <input type="text" name="questions[__I__][o<?php echo $oi; ?>]" placeholder="Option <?php echo $oi + 1; ?>" required>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </template>

                        <div style="display: flex; gap: 15px; margin-top: 20px; flex-wrap: wrap;">
                            <button type="button" class="btn btn-secondary" id="addQuestionBtn">+ Add Question</button>
                            <button type="button" class="btn btn-secondary" id="copyAllQuestionsBtn">Copy All</button>
                            <button type="submit" class="btn">💾 Save All Questions</button>
                        </div>
                    </form>
                    <script>
                        (function() {
                            let nextIndex = <?php echo count($adminEditRows); ?>;
                            const list = document.getElementById('questionList');
                            const template = document.getElementById('questionTemplate');

                            function questionText(card) {
                                const number = card.querySelector('.edit-question-number').textContent.trim();
                                const question = card.querySelector('textarea').value.trim();
                                const options = Array.from(card.querySelectorAll('.edit-option')).map(function(row, index) {
                                    const letter = String.fromCharCode(65 + index);
                                    const text = row.querySelector('input[type="text"]').value.trim();
                                    const correct = row.querySelector('input[type="radio"]').checked;
                                    return letter + '. ' + text + (correct ? '    ← correct' : '');
                                });
                                return number + '\n' + question + '\n' + options.join('\n');
                            }

                            function copyText(text, button) {
                                const done = function() {
                                    const original = button.textContent;
                                    button.textContent = 'Copied';
                                    setTimeout(function() { button.textContent = original; }, 1200);
                                };
                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                    navigator.clipboard.writeText(text).then(done).catch(function() {
                                        fallbackCopy(text);
                                        done();
                                    });
                                    return;
                                }
                                fallbackCopy(text);
                                done();
                            }

                            function fallbackCopy(text) {
                                const area = document.createElement('textarea');
                                area.value = text;
                                document.body.appendChild(area);
                                area.select();
                                document.execCommand('copy');
                                area.remove();
                            }

                            list.addEventListener('click', function(e) {
                                const button = e.target.closest('.copy-question-btn');
                                if (!button) return;
                                copyText(questionText(button.closest('.edit-question')), button);
                            });

                            document.getElementById('copyAllQuestionsBtn').addEventListener('click', function() {
                                const cards = Array.from(list.querySelectorAll('.edit-question'));
                                copyText(cards.map(questionText).join('\n\n'), this);
                            });

                            document.getElementById('addQuestionBtn').addEventListener('click', function() {
                                const html = template.innerHTML.replace(/__I__/g, nextIndex++);
                                list.insertAdjacentHTML('beforeend', html);
                                list.lastElementChild.scrollIntoView({ behavior: 'smooth' });
                            });
                            list.addEventListener('change', function(e) {
                                if (e.target.matches('.edit-delete input')) {
                                    const card = e.target.closest('.edit-question');
                                    card.classList.toggle('is-deleted', e.target.checked);
                                    card.querySelectorAll('textarea, input[type=text], input[type=radio]').forEach(function(el) {
                                        el.required = !e.target.checked;
                                    });
                                }
                            });
                        })();
                    </script>
                <?php else: ?>
                    <h1>✏️ Edit Questions</h1>
                    <div class="error">Quiz not found.</div>
                <?php endif; ?>
                <div style="margin-top: 30px;">
                    <a href="?admin=1" style="text-decoration: none;">
                        <button type="button" class="btn btn-secondary">← Back to Admin Dashboard</button>
                    </a>
                </div>

            <?php elseif (isset($_GET['quiz_key'])): ?>
                <!-- Admin Master Quiz Answer Key -->
                <?php if ($adminQuizKey): ?>
                    <h1>📋 เฉลยข้อสอบ: <?php echo htmlspecialchars($adminQuizKey['title']); ?></h1>
                    <p class="subtitle">
                        File: <?php echo htmlspecialchars($adminQuizKey['filename']); ?> ·
                        Total in CSV: <?php echo count($adminQuizKeyQuestions); ?> questions ·
                        Loaded per test: <?php echo (int)$adminQuizKey['questions_count']; ?> ·
                        Duration: <?php echo (int)$adminQuizKey['duration_minutes']; ?> min ·
                        Pass: <?php echo (int)$adminQuizKey['passing_score']; ?>%
                    </p>
                    <?php echo renderAnswerReview($adminQuizKeyQuestions, null); ?>
                <?php else: ?>
                    <h1>📋 เฉลยข้อสอบ</h1>
                    <div class="error">Quiz not found.</div>
                <?php endif; ?>
                <div style="margin-top: 30px;">
                    <a href="?admin=1" style="text-decoration: none;">
                        <button type="button" class="btn btn-secondary">← Back to Admin Dashboard</button>
                    </a>
                </div>

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
                    <h2 class="admin-section-title" style="margin-top: 0;">Test Results Report</h2>
                    <p style="color: #666; margin-bottom: 10px;">
                        Summary of completed quiz attempts (<?php echo count($quizAttempts); ?> total).
                    </p>
                    <?php if (count($quizAttempts) > 0): ?>
                        <div class="admin-table-wrap">
                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Date/Time</th>
                                    <th>Candidate</th>
                                    <th>Quiz</th>
                                    <th>Score</th>
                                    <th>Status</th>
                                    <th>Time Spent</th>
                                    <th>Left Page</th>
                                    <th>IP Address</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($quizAttempts as $i => $attempt): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars($attempt['completed_at'] ?? '-'); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($attempt['user_name'] ?? '-'); ?><br>
                                            <small><?php echo htmlspecialchars($attempt['user_email'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($attempt['quiz_title'] ?? '-'); ?></td>
                                        <td>
                                            <?php echo (int)($attempt['score'] ?? 0); ?>/<?php echo (int)($attempt['total'] ?? 0); ?>
                                            (<?php echo htmlspecialchars((string)($attempt['percentage'] ?? 0)); ?>%)
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo !empty($attempt['passed']) ? 'status-passed' : 'status-failed'; ?>" style="display:inline-block; margin:0; font-size:0.75em; padding:6px 10px;">
                                                <?php echo htmlspecialchars($attempt['status'] ?? '-'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($attempt['time_spent'] ?? '-'); ?></td>
                                        <td><?php echo isset($attempt['leave_count']) ? (int)$attempt['leave_count'] : '-'; ?></td>
                                        <td style="font-family: monospace; font-size: 0.85em;"><?php echo htmlspecialchars($attempt['ip_address'] ?? '-'); ?></td>
                                        <td>
                                            <div class="results-actions">
                                                <a href="?admin=1&review=<?php echo urlencode($attempt['id']); ?>" style="text-decoration:none;">
                                                    <button type="button" class="btn btn-sm" style="width: 100%;">Show Answer Key</button>
                                                </a>
                                                <form method="POST" action="?admin=1" style="margin: 0;" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode('Delete the result of ' . ($attempt['user_name'] ?? '-') . ' (' . ($attempt['quiz_title'] ?? '-') . ')? This cannot be undone.'), ENT_QUOTES); ?>);">
                                                    <input type="hidden" name="admin_action" value="delete_attempt">
                                                    <input type="hidden" name="attempt_id" value="<?php echo htmlspecialchars($attempt['id'] ?? ''); ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" style="width: 100%;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php else: ?>
                        <p style="color: #999;">No test results yet.</p>
                    <?php endif; ?>

                    <h2 class="admin-section-title">Upload New Quiz</h2>
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
                            <label for="duration_minutes">Total Quiz Duration (minutes)</label>
                            <input type="number" id="duration_minutes" name="duration_minutes" value="<?php echo defaultDurationMinutes($DEFAULT_QUESTIONS_TO_LOAD); ?>" min="1" required>
                            <small style="color: #666; display: block; margin-top: 5px;">
                                Default is 1 minute per question (auto-calculated from questions above). You can override this value.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="csv_file">Select CSV File</label>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                        </div>
                        
                        <button type="submit" class="btn">Upload Quiz</button>
                    </form>

                    <h2 style="margin-top: 40px;">Available Quizzes (<?php echo count($availableQuizzes); ?> total)</h2>
                    <p style="color: #666; margin-bottom: 10px;">Edit quiz parameters below and click Save for that row.</p>
                    
                    <?php if (count($availableQuizzes) > 0): ?>
                        <div class="admin-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Quiz Title</th>
                                    <th>CSV Filename</th>
                                    <th>Questions</th>
                                    <th>Passing %</th>
                                    <th>Duration (min)</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($availableQuizzes as $index => $quiz): ?>
                                    <?php $formId = 'edit_quiz_' . $index; ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <input form="<?php echo $formId; ?>" type="text" name="quiz_title" value="<?php echo htmlspecialchars($quiz['title']); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
                                        </td>
                                        <td style="font-size: 0.85em; word-break: break-all;"><?php echo htmlspecialchars($quiz['filename']); ?></td>
                                        <td>
                                            <input form="<?php echo $formId; ?>" type="number" name="questions_to_load" class="edit-questions" value="<?php echo (int)$quiz['questions_count']; ?>" min="1" required style="width: 70px; padding: 8px; border: 1px solid #ddd; border-radius: 6px;" data-duration-target="edit_duration_<?php echo $index; ?>">
                                        </td>
                                        <td>
                                            <input form="<?php echo $formId; ?>" type="number" name="passing_score" value="<?php echo (int)$quiz['passing_score']; ?>" min="1" max="100" required style="width: 70px; padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
                                        </td>
                                        <td>
                                            <input form="<?php echo $formId; ?>" type="number" name="duration_minutes" id="edit_duration_<?php echo $index; ?>" value="<?php echo (int)$quiz['duration_minutes']; ?>" min="1" required style="width: 70px; padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                                <?php if ($index === 0): ?>
                                                    <button type="button" class="btn btn-sm" onclick="alert('บนสุดแล้ว เลื่อนไม่ได้')">Move Up</button>
                                                <?php else: ?>
                                                    <form method="POST" action="?admin=1" style="margin: 0;">
                                                        <input type="hidden" name="admin_action" value="move_quiz_up">
                                                        <input type="hidden" name="quiz_index" value="<?php echo $index; ?>">
                                                        <button type="submit" class="btn btn-sm">Move Up</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form id="<?php echo $formId; ?>" method="POST" style="margin: 0;">
                                                    <input type="hidden" name="admin_action" value="update_quiz">
                                                    <input type="hidden" name="quiz_index" value="<?php echo $index; ?>">
                                                    <button type="submit" class="btn btn-sm">Save</button>
                                                </form>
                                                <form method="POST" action="?admin=1" enctype="multipart/form-data" style="margin: 0;" class="replace-csv-form">
                                                    <input type="hidden" name="admin_action" value="replace_csv">
                                                    <input type="hidden" name="quiz_index" value="<?php echo $index; ?>">
                                                    <input type="file" name="csv_file" accept=".csv" style="display: none;" data-quiz-title="<?php echo htmlspecialchars($quiz['title']); ?>">
                                                    <button type="button" class="btn btn-success btn-sm replace-csv-btn">Upload</button>
                                                </form>
                                                <a href="?admin=1&quiz_edit=<?php echo $index; ?>" style="text-decoration: none;">
                                                    <button type="button" class="btn btn-secondary btn-sm">Edit</button>
                                                </a>
                                                <a href="?admin=1&quiz_key=<?php echo $index; ?>" style="text-decoration: none;">
                                                    <button type="button" class="btn btn-secondary btn-sm">เฉลย</button>
                                                </a>
                                                <a href="?admin=1&download_quiz=<?php echo $index; ?>" class="btn btn-secondary btn-sm" style="text-decoration: none; text-align: center;">Download CSV</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php else: ?>
                        <p style="margin-top: 20px; color: #999; text-align: center;">No quizzes uploaded yet.</p>
                    <?php endif; ?>
                </div>
                <script>
                    (function() {
                        const secondsPerQuestion = <?php echo (int)$SECONDS_PER_QUESTION; ?>;
                        const questionsInput = document.getElementById('questions_to_load');
                        const durationInput = document.getElementById('duration_minutes');
                        let durationManuallyEdited = false;

                        if (durationInput) {
                            durationInput.addEventListener('input', function() {
                                durationManuallyEdited = true;
                            });
                        }

                        function syncUploadDuration() {
                            if (!questionsInput || !durationInput || durationManuallyEdited) return;
                            const count = Math.max(1, parseInt(questionsInput.value, 10) || 1);
                            durationInput.value = Math.ceil((count * secondsPerQuestion) / 60);
                        }

                        if (questionsInput) {
                            questionsInput.addEventListener('input', syncUploadDuration);
                            syncUploadDuration();
                        }

                        document.querySelectorAll('.replace-csv-form').forEach(function(form) {
                            const fileInput = form.querySelector('input[type=file]');
                            form.querySelector('.replace-csv-btn').addEventListener('click', function() {
                                fileInput.click();
                            });
                            fileInput.addEventListener('change', function() {
                                if (!fileInput.files.length) return;
                                const title = fileInput.getAttribute('data-quiz-title');
                                if (confirm('Replace all questions in "' + title + '" with ' + fileInput.files[0].name + '?')) {
                                    form.submit();
                                } else {
                                    fileInput.value = '';
                                }
                            });
                        });

                        document.querySelectorAll('.edit-questions').forEach(function(input) {
                            input.addEventListener('change', function() {
                                const targetId = input.getAttribute('data-duration-target');
                                const target = document.getElementById(targetId);
                                if (!target) return;
                                const count = Math.max(1, parseInt(input.value, 10) || 1);
                                target.value = Math.ceil((count * secondsPerQuestion) / 60);
                            });
                        });
                    })();
                </script>

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

        <?php elseif ($showResults): ?>
            <!-- Results + Full Answer Key -->
            <?php
            $results = $_SESSION['quiz_results'];
            $reviewQuestions = $results['questions'] ?? $questions;
            $reviewAnswers = $results['answers'] ?? ($_SESSION['quiz_answers'] ?? []);
            echo renderResultsSummaryCard($results);
            ?>
            <h2 style="margin: 30px 0 10px;">Full Answer Key</h2>
            <p style="color:#666; margin-bottom: 10px;">Green = correct answer · Blue = your correct choice · Red = your incorrect choice</p>
            <?php echo renderAnswerReview($reviewQuestions, $reviewAnswers); ?>

            <div style="display: flex; gap: 15px; margin-top: 30px;">
                <a href="?select_another=1" style="text-decoration: none; flex: 1;">
                    <button class="btn btn-success" style="width: 100%;">📚 Take Another Quiz</button>
                </a>
                <a href="?logout=1" style="text-decoration: none; flex: 1;">
                    <button class="btn btn-outline" style="width: 100%;">Logout</button>
                </a>
            </div>

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
                        <p>⏱️ <strong>Total duration:</strong> <?php echo (int)$quiz['duration_minutes']; ?> minute<?php echo ((int)$quiz['duration_minutes'] === 1) ? '' : 's'; ?></p>
                        <p>📊 <strong>Avg. time per question:</strong> <?php echo $quiz['questions_count'] > 0 ? round(($quiz['duration_minutes'] * 60) / $quiz['questions_count']) : $SECONDS_PER_QUESTION; ?> seconds</p>
                        <p>🎯 <strong>Passing Score:</strong> <?php echo $quiz['passing_score']; ?>%</p>
                        <form method="POST" class="quiz-start-form" data-title="<?php echo htmlspecialchars($quiz['title']); ?>" data-questions="<?php echo (int)$quiz['questions_count']; ?>" data-minutes="<?php echo (int)$quiz['duration_minutes']; ?>">
                            <input type="hidden" name="quiz_index" value="<?php echo $index; ?>">
                            <input type="hidden" name="select_quiz" value="1">
                            <button type="button" class="btn quiz-select-btn">Start This Quiz →</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="error">No quizzes available. Please contact the administrator.</div>
            <?php endif; ?>

            <div class="quiz-briefing" id="quizBriefing" hidden>
                <div class="quiz-briefing-card" role="dialog" aria-modal="true" aria-labelledby="quizBriefingTitle">
                    <div class="quiz-briefing-hero">
                        <div class="quiz-briefing-top">
                            <div class="quiz-briefing-kicker" data-i18n="kicker"></div>
                            <div class="quiz-briefing-lang">
                                <button type="button" class="lang-btn is-active" data-lang="th">TH</button>
                                <button type="button" class="lang-btn" data-lang="en">EN</button>
                            </div>
                        </div>
                        <h2 id="quizBriefingTitle"></h2>
                        <p data-i18n="intro"></p>
                    </div>
                    <div class="quiz-briefing-body">
                        <div class="quiz-briefing-stats">
                            <div class="quiz-briefing-stat">
                                <strong data-i18n="choiceTitle"></strong>
                                <span data-i18n="choiceText"></span>
                            </div>
                            <div class="quiz-briefing-stat">
                                <strong id="quizBriefingCount"></strong>
                                <span data-i18n="countText"></span>
                            </div>
                            <div class="quiz-briefing-stat">
                                <strong id="quizBriefingMinutes"></strong>
                                <span data-i18n="minutesText"></span>
                            </div>
                            <div class="quiz-briefing-stat">
                                <strong data-i18n="oneTitle"></strong>
                                <span data-i18n="oneText"></span>
                            </div>
                        </div>
                        <ul class="quiz-briefing-list">
                            <li data-i18n="rule1"></li>
                            <li data-i18n="rule2"></li>
                            <li data-i18n="rule3"></li>
                            <li class="caution" data-i18n="rule4"></li>
                            <li class="caution" data-i18n="rule5"></li>
                        </ul>
                        <div class="quiz-briefing-actions">
                            <button type="button" class="btn btn-outline" id="quizBriefingCancel" data-i18n="back"></button>
                            <button type="button" class="btn" id="quizBriefingOk">OK</button>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                (function() {
                    const dialog = document.getElementById('quizBriefing');
                    const title = document.getElementById('quizBriefingTitle');
                    const count = document.getElementById('quizBriefingCount');
                    const minutes = document.getElementById('quizBriefingMinutes');
                    let pendingForm = null;
                    let lang = 'th';
                    const copy = {
                        th: {
                            kicker: 'ก่อนเริ่มทำข้อสอบ',
                            intro: 'กรุณาอ่านและกด OK เพื่อยอมรับก่อนเริ่ม',
                            choiceTitle: 'ปรนัย',
                            choiceText: 'เลือกคำตอบที่ถูกต้อง',
                            countText: 'จำนวนข้อที่ต้องทำ',
                            minutesText: 'เวลาทั้งหมดของข้อสอบ',
                            oneTitle: 'ทีละ 1 ข้อ',
                            oneText: 'แสดงคำถามครั้งละหนึ่งข้อ',
                            rule1: 'ข้อสอบจะแสดงทีละหนึ่งข้อ จนกว่าจะตอบครบหรือหมดเวลา',
                            rule2: 'ระบบอาจตรวจจับหน้าจอเป็นระยะเพื่อป้องกันการทุจริต',
                            rule3: 'แนะนำให้เข้าห้องน้ำให้เรียบร้อยก่อนเริ่มทำข้อสอบ',
                            rule4: 'ห้ามทุจริตโดยใช้ AI ช่วยในการตอบ',
                            rule5: 'ห้ามคัดลอกข้อสอบออกไปตรวจสอบนอกเบราว์เซอร์',
                            back: 'กลับ'
                        },
                        en: {
                            kicker: 'Before you start',
                            intro: 'Please read this and press OK to accept before starting',
                            choiceTitle: 'Multiple choice',
                            choiceText: 'Choose the correct answer',
                            countText: 'Questions in this quiz',
                            minutesText: 'Total time',
                            oneTitle: 'One at a time',
                            oneText: 'One question is shown at a time',
                            rule1: 'The quiz shows one question at a time until you finish or time runs out.',
                            rule2: 'The system may check the screen periodically to prevent cheating.',
                            rule3: 'Please use the restroom before you start the quiz.',
                            rule4: 'Do not use AI to help answer the questions.',
                            rule5: 'Do not copy the quiz out of the browser to check it.',
                            back: 'Back'
                        }
                    };

                    function applyLanguage() {
                        const text = copy[lang];
                        dialog.querySelectorAll('[data-i18n]').forEach(function(el) {
                            const key = el.getAttribute('data-i18n');
                            if (text[key]) el.textContent = text[key];
                        });
                        dialog.querySelectorAll('.lang-btn').forEach(function(btn) {
                            const active = btn.getAttribute('data-lang') === lang;
                            btn.classList.toggle('is-active', active);
                            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                        });
                        if (!pendingForm) return;
                        const questionCount = pendingForm.getAttribute('data-questions') || '0';
                        const duration = pendingForm.getAttribute('data-minutes') || '0';
                        count.textContent = lang === 'th'
                            ? questionCount + ' ข้อ'
                            : questionCount + (questionCount === '1' ? ' question' : ' questions');
                        minutes.textContent = lang === 'th'
                            ? duration + ' นาที'
                            : duration + (duration === '1' ? ' minute' : ' minutes');
                    }

                    function openBriefing(form) {
                        pendingForm = form;
                        title.textContent = form.getAttribute('data-title') || '';
                        applyLanguage();
                        dialog.hidden = false;
                        document.getElementById('quizBriefingOk').focus();
                    }

                    function closeBriefing() {
                        dialog.hidden = true;
                        pendingForm = null;
                    }

                    dialog.querySelectorAll('.lang-btn').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            lang = btn.getAttribute('data-lang') === 'en' ? 'en' : 'th';
                            applyLanguage();
                        });
                    });

                    document.querySelectorAll('.quiz-start-form').forEach(function(form) {
                        form.querySelector('.quiz-select-btn').addEventListener('click', function() {
                            openBriefing(form);
                        });
                    });

                    document.getElementById('quizBriefingOk').addEventListener('click', function() {
                        if (!pendingForm) return;
                        const form = pendingForm;
                        pendingForm = null;
                        form.submit();
                    });

                    document.getElementById('quizBriefingCancel').addEventListener('click', closeBriefing);
                    dialog.addEventListener('click', function(event) {
                        if (event.target === dialog) closeBriefing();
                    });
                    document.addEventListener('keydown', function(event) {
                        if (event.key === 'Escape' && !dialog.hidden) closeBriefing();
                    });
                })();
            </script>

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

            <div style="display: flex; justify-content: flex-end; margin: 12px 0;">
                <button type="button" class="btn btn-sm" id="enterFullscreen">เต็มจอ</button>
            </div>

            <?php if ($currentQuestion): ?>
                <form method="POST" id="quizForm" class="no-copy">
                    <input type="hidden" name="current_index" value="<?php echo $currentQuestionIndex; ?>">
                    <input type="hidden" id="formAction" name="action" value="next_question">
                    <input type="hidden" id="leaveCountInput" name="leave_count" value="0">

                    <div class="question-card">
                        <div class="question-header">
                            <div class="question-number">
                                Question <?php echo $currentQuestionIndex + 1; ?> of <?php echo count($questions); ?>
                            </div>
                            <div class="timer" id="timer">
                                <span>⏱️</span>
                                <span id="timeDisplay"><?php
                                    $displayRemaining = isset($_SESSION['start_time'], $_SESSION['quiz_duration_seconds'])
                                        ? max(0, (int)$_SESSION['quiz_duration_seconds'] - (time() - (int)$_SESSION['start_time']))
                                        : ((int)($selectedQuiz['duration_minutes'] ?? 1) * 60);
                                    echo floor($displayRemaining / 60) . ':' . str_pad((string)($displayRemaining % 60), 2, '0', STR_PAD_LEFT);
                                ?></span>
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
                <div class="quiz-briefing" id="leaveWarning" hidden>
                    <div class="quiz-briefing-card" role="dialog" aria-modal="true" aria-labelledby="leaveWarningTitle">
                        <div class="quiz-briefing-hero">
                            <div class="quiz-briefing-kicker">ข้อสอบกำลังดำเนินอยู่</div>
                            <h2 id="leaveWarningTitle">ไม่อนุญาตให้ออกจากการสอบ</h2>
                        </div>
                        <div class="quiz-briefing-body">
                            <p id="leaveWarningText" style="color: #333; line-height: 1.5; margin-bottom: 16px;"></p>
                            <button type="button" class="btn" id="leaveWarningOk">OK</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        if (document.getElementById('quizForm')) {
            ['copy', 'cut', 'contextmenu', 'dragstart', 'selectstart'].forEach(function(type) {
                document.addEventListener(type, function(e) { e.preventDefault(); });
            });
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && ['c', 'a', 's', 'p'].includes(e.key.toLowerCase())) {
                    e.preventDefault();
                }
            });

            const quizSessionId = '<?php echo isset($_SESSION['start_time']) ? (int)$_SESSION['start_time'] : 0; ?>';
            const storageKey = 'quiz_leave_count';
            const sessionKey = 'quiz_leave_session';
            if (sessionStorage.getItem(sessionKey) !== quizSessionId) {
                sessionStorage.setItem(sessionKey, quizSessionId);
                sessionStorage.setItem(storageKey, '0');
            }
            let leaveCount = parseInt(sessionStorage.getItem(storageKey) || '0', 10) || 0;
            let away = false;
            let seenFocus = document.hasFocus();
            let armed = false;
            let lastCountedAt = 0;
            let fullscreenActive = !!(document.fullscreenElement || document.webkitFullscreenElement);
            const leaveInput = document.getElementById('leaveCountInput');
            const warning = document.getElementById('leaveWarning');
            const warningText = document.getElementById('leaveWarningText');
            const fullscreenButton = document.getElementById('enterFullscreen');

            function syncLeaveCount() {
                sessionStorage.setItem(storageKey, String(leaveCount));
                if (leaveInput) leaveInput.value = String(leaveCount);
            }
            syncLeaveCount();

            function countLeave() {
                const now = Date.now();
                if (now - lastCountedAt < 800) return;
                lastCountedAt = now;
                leaveCount += 1;
                syncLeaveCount();
            }

            function showLeaveWarning() {
                if (!warning || !warningText) return;
                warningText.textContent = 'ระบบบันทึกว่าออกจากหน้าข้อสอบแล้ว ' + leaveCount + ' ครั้ง';
                warning.hidden = false;
            }

            function markAway() {
                if (!armed || !seenFocus || away) return;
                away = true;
                countLeave();
            }

            function markBack() {
                if (!away || document.hidden) return;
                away = false;
                showLeaveWarning();
            }

            setTimeout(function() { armed = true; }, 1000);
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) markAway();
                else markBack();
            });
            window.addEventListener('blur', markAway);
            window.addEventListener('focus', function() {
                seenFocus = true;
                markBack();
            });

            function updateFullscreenButton() {
                if (!fullscreenButton) return;
                fullscreenButton.textContent = fullscreenActive ? 'อยู่ในเต็มจอ' : 'เต็มจอ';
            }
            function onFullscreenChange() {
                const active = !!(document.fullscreenElement || document.webkitFullscreenElement);
                if (fullscreenActive && !active) {
                    countLeave();
                    showLeaveWarning();
                }
                fullscreenActive = active;
                updateFullscreenButton();
            }
            updateFullscreenButton();

            if (fullscreenButton) {
                fullscreenButton.addEventListener('click', function() {
                    const element = document.documentElement;
                    const request = element.requestFullscreen || element.webkitRequestFullscreen;
                    if (request) request.call(element);
                });
            }
            document.addEventListener('fullscreenchange', onFullscreenChange);
            document.addEventListener('webkitfullscreenchange', onFullscreenChange);

            document.getElementById('leaveWarningOk').addEventListener('click', function() {
                warning.hidden = true;
            });
        }

        // Total quiz duration timer (counts down across all questions)
        <?php
        $remainingSeconds = 0;
        if (isset($_SESSION['start_time'], $_SESSION['quiz_duration_seconds'])) {
            $remainingSeconds = max(0, (int)$_SESSION['quiz_duration_seconds'] - (time() - (int)$_SESSION['start_time']));
        }
        $warningThreshold = max(30, (int)floor($remainingSeconds * 0.1));
        ?>
        let timeLeft = <?php echo (int)$remainingSeconds; ?>;
        const warningThreshold = <?php echo (int)$warningThreshold; ?>;
        let timerInterval;

        function updateTimer() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            const timeDisplay = document.getElementById('timeDisplay');
            const timer = document.getElementById('timer');
            
            if (timeDisplay) {
                timeDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                
                if (timeLeft <= warningThreshold) {
                    timer.classList.add('warning');
                } else {
                    timer.classList.remove('warning');
                }
                
                timeLeft--;
                
                if (timeLeft < 0) {
                    clearInterval(timerInterval);
                    const formAction = document.getElementById('formAction');
                    if (formAction) {
                        formAction.value = 'finish_quiz';
                    }
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
