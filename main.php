<?php

ini_set('log_errors', 1);
ini_set('error_log', '/home/g/ghulqul/facehookapp.ru/public_html/source/FaceApp/php_errors.log');
error_reporting(E_ALL & ~E_NOTICE); // Устанавливаем уровень ошибок

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Получаем конфиденциальную информацию из переменных окружения
$token = getenv('TOKEN');
$host = getenv('HOST');
$user = getenv('USER');
$password = getenv('PASSWORD');
$database = getenv('DATABASE');

$data = json_decode(file_get_contents('php://input'), TRUE);
//file_put_contents('file.txt', '$data: '.print_r($data, 1)."\n", FILE_APPEND);
$mysqli = new mysqli($host, $user, $password, $database);
$mysqli->set_charset('utf8mb4');

// Подготовьте данные для вставки в таблицу
$update_id = $data['update_id'];
$message_id = $data['message']['message_id'] ?? $data['callback_query']['message']['message_id'];
$chat_id = $data['message']['chat']['id'] ?? $data['callback_query']['message']['chat']['id'];
$username = $data['message']['from']['username'] ?? $data['callback_query']['from']['username'];
$text = $data['message']['text'] ?? $data['callback_query']['data'];
//Проверка на запрещённые символы
if (blackList($text) == true) {
    sendTelegramMessage($token, $chat_id, 'Найден запрещённый символ', 0, $mysqli);
    return;
}
$location = $data['message']['location'];
$i=0;
$check_file_id = $data['message']['photo'][$i]['file_id'];
// Выбор наилучшего разрешения фото
while (isset($check_file_id)) {
    $i++;
    $check_file_id = $data['message']['photo'][$i]['file_id'];
}
$i -= 1;
$file_id = $data['message']['photo'][$i]['file_id'];
$video_id = $data['message']['video']['file_id'];

// SQL-запрос для вставки в лог
$sql = "INSERT INTO msg_webhook (update_id, message_id, chat_id, username, text, reg_step)
        VALUES ('$update_id', '$message_id', '$chat_id', '$username', '$text', 0)";
$mysqli->query($sql);



function editTelegramMessage($token, $chat_id, $step, $mysqli) {
    $sql = "SELECT message_id FROM msg_webhook WHERE chat_id = '$chat_id' AND (text = '/distance' OR text = '/filter'
                                                                            OR text = '/myprofilemenu' OR text = '/matchmenu'
                                                                            OR text = '/combacktostartmatches' OR text = '/combacktostartmenu')
                                                                            ORDER BY id DESC LIMIT 1";
    $result = $mysqli->query($sql);
    $message_id = $result->fetch_assoc();
    switch ($step) {
//Выбор месяца
        case 0:
            $currentMonth = date("m");
            switch ($currentMonth) {
                case "01": 
                    $currentMonthName = "Январь";
                    $nextMonthName = "Февраль";
                    break;
                case "02":
                    $currentMonthName = "Февраль";
                    $nextMonthName = "Март";
                    break;
                case "03":
                    $currentMonthName = "Март";
                    $nextMonthName = "Апрель";
                    break;
                case "04":
                    $currentMonthName = "Апрель";
                    $nextMonthName = "Май";
                    break;
                case "05":
                    $currentMonthName = "Май";
                    $nextMonthName = "Июнь";
                    break;
                case "06":
                    $currentMonthName = "Июнь";
                    $nextMonthName = "Июль";
                    break;
                case "07":
                    $currentMonthName = "Июль";
                    $nextMonthName = "Август";
                    break;
                case "08":
                    $currentMonthName = "Август";
                    $nextMonthName = "Сентябрь";
                    break;
                case "09":
                    $currentMonthName = "Сентябрь";
                    $nextMonthName = "Октябрь";
                    break;
                case "10":
                    $currentMonthName = "Октябрь";
                    $nextMonthName = "Ноябрь";
                    break;
                case "11":
                    $currentMonthName = "Ноябрь";
                    $nextMonthName = "Декабрь";
                    break;
                case "12":
                    $currentMonthName = "Декабрь";
                    $nextMonthName = "Январь";
                    break;
            }
        $getQuery = array(
            "chat_id" => $chat_id,
            "message_id" => $message_id['message_id'],
            "text" => 'Выберите месяц',
            'reply_markup' => json_encode(array(
                'inline_keyboard' => array(
                    array(
                        array(
                            'text' => 'Запись на '.$currentMonthName,
                            'callback_data' => '/currentmonth',
                        ),
                    ),
                    array(
                        array(
                            'text' => 'Запись на '.$nextMonthName,
                            'callback_data' => '/nextmonth',
                        ),
                    ),
                ),
            )),
        );
        break;
        }
    $ch = curl_init("https://api.telegram.org/bot". $token ."/editMessageText?" . http_build_query($getQuery));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_exec($ch);
    curl_close($ch);
    return;
}

//Функция отправки сообщения
function sendTelegramMessage($token, $chat_id, $text, $reg_step, $mysqli) {
    $getQuery = [];
    switch ($reg_step) {
    //Отправка текста
        case 0:
            $getQuery = array(
                "chat_id" 	=> $chat_id,
                "text" => $text,
                'disable_notification' => true,
            );
            break;
    //Вызов /start
        case 1:
            $sqlСheckReg = "SELECT * FROM users WHERE chat_id = '$chat_id'";
            $result = $mysqli->query($sqlСheckReg);
            $getQuery = array(
                "chat_id" => $chat_id,
                "text" => $text,
                'disable_notification' => true,
                'remove_keyboard' => true,
                'reply_markup' => json_encode(array(
                    'inline_keyboard' => array(
                        array(
                            array(
                                'text' => 'Записаться',
                                'callback_data' => '/newentry',
                            ),
                            array(
                                'text' => 'Мои записи',
                                'callback_data' => '/myentry',
                            ),
                        ),
                    ),
                )),
            );
            break;
    $ch = curl_init("https://api.telegram.org/bot". $token ."/sendMessage?" . http_build_query($getQuery));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_exec($ch);
    curl_close($ch);
    }
}

//Функция проверки строки на запрещённые символы
function blackList($str) {
  $blacklist = array(
    "DROP",
    "DELETE",
    "TRUNCATE",
    "ALTER",
    "UPDATE",
    "INSERT",
    "SELECT",
    ";",
    "--",
    "#",
    "\\",
    "=",
    ">",
    "<",
    ">=",
    "<=",
    "UNION",
    "OR",
    "AND",
    "EXEC",
    "CONCAT",
  );

  // Разбиваем входную строку на слова
  $words = preg_split('/\s+/', $str);

  // Проверка на наличие запрещенных слов
  foreach ($words as $word) {
    if (in_array(strtoupper($word), $blacklist)) {
        return true; // Найдено запрещенное слово
    }
  }

  return false; // Запрещенных слов не найдено
}

// Функция обработки команд от пользователя
function processSwitchCommand($token, $chat_id, $username, $text, $file_id, $mysqli) {
    $sqlShowFlag = "SELECT * FROM users WHERE chat_id = '$chat_id'";
    $resultSqlShowFlag = $mysqli->query($sqlShowFlag);
    $showFlag = $resultSqlShowFlag->fetch_assoc();
    $currentMonth = date("m");
    $nextMonth = date("m", strtotime("+1 month"));
//Вызов главного меню
    if ($text == '/start') {
      $sqlFilter = ("UPDATE users SET main_menu_flag = true WHERE chat_id = '$chat_id'");
      $mysqli->query($sqlFilter);
      sendTelegramMessage($token, $chat_id, "Главное меню", 1, $mysqli);
    }
//Обработка callback
    //Новая запись
    else if ($text == '/newentry') {
      editTelegramMessage($token, $chat_id, 0, $mysqli);
      return;
    }
    //Мои записи
    else if ($text == '/myentry') {

    }
    else if ($text == '/currentmonth') {
      $sqlFreeWindows = ("SELECT * FROM free_windows WHERE month = '$currentMonth'");
      $resultFreeWindows = $mysqli->query($sqlFreeWindows);
      if ($resultFreeWindows->num_rows() == 0) {
        sendTelegramMessage($token, $chat_id, "На выбранный месяц нет свободных окошек :( Попробуй записаться на следующий месяц", 2, $mysqli);
      }
      else {
        
      }
    }
    else if ($text == '/nextmonth') {
      $sqlFilter = ("SELECT * FROM free_windows WHERE month = '$nextMonth'");
      $mysqli->query($sqlFilter);
    }
}

$mysqli->close();