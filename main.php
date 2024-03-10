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
//Меню фильтрации
        case 0:
            $sqlFilter = "SELECT filter_location, favorite_gender, favorite_age_min, favorite_age_max, show_flag FROM users WHERE chat_id = '$chat_id'";
            $resultFilter = $mysqli->query($sqlFilter);
            $filter = $resultFilter->fetch_assoc();
            if ($filter['filter_location'] == 'global') {
              $filter_location = 'без ограничений';
            }
            elseif ($filter['filter_location'] == 'local') {
              $filter_location = 'по городу';
            }
            if ($filter['favorite_gender'] == 'Женский') {
                $favorite_gender = 'Девушки';
            }
            elseif ($filter['favorite_gender'] == 'Мужской') {
                $favorite_gender = 'Парни';
            }
            elseif ($filter['favorite_gender'] == 'Все') {
                $favorite_gender = 'Все';
            }
            //Меню фильтрации при поиске
            if ($filter['show_flag'] == true) {
                $getQuery = array(
                    "chat_id" => $chat_id,
                    "message_id" => $message_id['message_id'],
                    "text" => 'Настройка фильтра показа анкет:',
                    'reply_markup' => json_encode(array(
                        'inline_keyboard' => array(
                            array(
                                array(
                                    'text' => 'Расстояние поиска: '.$filter_location,
                                    'callback_data' => '/distance',
                                ),
                            ),
                            array(
                                array(
                                    'text' => 'Возраст: '.$filter['favorite_age_min'].'-'.$filter['favorite_age_max'],
                                    'callback_data' => '/age',
                                ),
                            ),
                            array(
                                array(
                                    'text' => 'Кого вы ищете: '.$favorite_gender,
                                    'callback_data' => '/favorite_gender',
                                ),
                            ),
                            array(
                                array(
                                    'text' => 'Продолжить просмотр анкет',
                                    'callback_data' => '/combacktostartmatches',
                                ),
                            ),
                        ),
                    )),
                );
                break;
            }
            //Меню фильтрации из Главного меню
            else {
                $getQuery = array(
                    "chat_id" 	=> $chat_id,
                    "message_id" => $message_id['message_id'],
                    "text" => 'Настройка фильтра показа анкет:',
                    'reply_markup' => json_encode(array(
                        'inline_keyboard' => array(
                            array(
                                array(
                                    'text' => 'Расстояние поиска: '.$filter_location,
                                    'callback_data' => '/distance',
                                ),
                            ),
                            array(
                                array(
                                    'text' => 'Возраст: '.$filter['favorite_age_min'].'-'.$filter['favorite_age_max'],
                                    'callback_data' => '/age',
                                ),
                            ),
                            array(
                                array(
                                    'text' => 'Кого вы ищете: '.$favorite_gender,
                                    'callback_data' => '/favorite_gender',
                                ),
                            ),
                            array(
                                array(
                                    'text' => '<< В главное меню',
                                    'callback_data' => '/combacktostartmenu',
                                ),
                            ),
                        ),
                    )),
                );
                break;
            }
//Меню Ваши пары
        case 1:
            $sqlLikeQueue = "SELECT id FROM rate WHERE (second_id = '$chat_id' and first_rate = true and second_rate IS NULL)
                                                    OR (first_id = '$chat_id' and second_rate = true and first_rate IS NULL)";
            $resultLikeQueue = $mysqli->query($sqlLikeQueue);
            $countLikes = $resultLikeQueue->num_rows;
            $sqlMatches = "SELECT id FROM rate WHERE (first_id = '$chat_id' OR second_id = '$chat_id') AND (first_rate = true AND second_rate = true)";
            $resultMatches = $mysqli->query($sqlMatches);
            $countMatches = $resultMatches->num_rows;
            $getQuery = array(
                "chat_id" => $chat_id,
				        "message_id" => $message_id['message_id'],
                "text" => 'Ваши лайки:',
                'reply_markup' => json_encode(array(
                    'inline_keyboard' => array(
                        array(
                            array(
                                'text' => "Ваши пары ($countMatches)",
                                'callback_data' => '/matches',
                            ),
                        ),
                        array(
                            array(
                                'text' => "Анкеты которым вы понравились ($countLikes)",
                                'callback_data' => '/checklike',
                            ),
                        ),
                        array(
                            array(
                                'text' => '<< В главное меню',
                                'callback_data' => '/combacktostartmenu',
                            ),
                        ),
                    ),
                )),
            );
            break;
//Меню Моя анкета
        case 2:
            //Проверка статуса university
            $sqlUniversity = "SELECT university FROM users WHERE chat_id = '$chat_id'";
            $resultUniversity = $mysqli->query($sqlUniversity);
            $university = $resultUniversity->fetch_assoc();
            //Проверка статуса SoulMate
            $sqlStatusTest = "SELECT test_step FROM users WHERE chat_id = '$chat_id'";
            $resultStatusTest = $mysqli->query($sqlStatusTest);
            $statusTest = $resultStatusTest->fetch_assoc();
            //Проверка статуса ЗЗ
            $sqlStatusZodiac = "SELECT zodiac_sign FROM zodiac_users WHERE chat_id = '$chat_id'";
            $resultStatusZodiac = $mysqli->query($sqlStatusZodiac);
            $statusZodiac = $resultStatusZodiac->fetch_assoc();
            //Проверка статуса верификации
            $sqlStatusVerification = "SELECT result FROM verification_users WHERE chat_id = '$chat_id'";
            $resultStatusVerification = $mysqli->query($sqlStatusVerification);
            $statusVerification = $resultStatusVerification->fetch_assoc();
            //Если университет не установлен
            if ($university ['university'] == null) {
              //Если SM пройден
              if ($statusTest ['test_step'] == 10) {
                  //Если ЗЗ не установлен
                  if ($resultStatusZodiac->num_rows == 0) {
                    //Если Верификация не пройдена
                    if ($statusVerification ['result'] == 2 || $statusVerification ['result'] == 0) {
                      $getQuery = array(
                          "chat_id" => $chat_id,
                          "message_id" => $message_id['message_id'],
                          "text" => 'Моя анкета:',
                          'reply_markup' => json_encode(array(
                              'inline_keyboard' => array(
                                  // array(
                                  //     array(
                                  //         'text' => 'Верификация: ✖️',
                                  //         'callback_data' => '/verification',
                                  //     ),
                                  // ),
                                  array(
                                    array(
                                        'text' => 'Указать учебное заведение:',
                                        'callback_data' => '/study',
                                    ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Soul Mate тест: ✅',
                                          'callback_data' => '/soulmatetest',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Знак зодиака: ✖️',
                                          'callback_data' => '/zodiacsign',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Редактировать мою анкету',
                                          'callback_data' => '/register',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Показать мою анкету',
                                          'callback_data' => '/showprofile',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => '<< В главное меню',
                                          'callback_data' => '/combacktostartmatches',
                                      ),
                                  ),
                              ),
                          )),
                      );
                      break;
                    }
                    //Если Верификация пройдена
                    if ($statusVerification ['result'] == 1) {
                    $getQuery = array(
                      "chat_id" => $chat_id,
                      "message_id" => $message_id['message_id'],
                      "text" => 'Моя анкета:',
                      'reply_markup' => json_encode(array(
                          'inline_keyboard' => array(
                              // array(
                              //     array(
                              //         'text' => 'Верификация: ✅',
                              //         'callback_data' => '/verification',
                              //     ),
                              // ),
                              array(
                                array(
                                    'text' => 'Указать учебное заведение:',
                                    'callback_data' => '/study',
                                ),
                              ),
                              array(
                                  array(
                                      'text' => 'Soul Mate тест: ✅',
                                      'callback_data' => '/soulmatetest',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => 'Знак зодиака: ✖️',
                                      'callback_data' => '/zodiacsign',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => 'Редактировать мою анкету',
                                      'callback_data' => '/register',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => 'Показать мою анкету',
                                      'callback_data' => '/showprofile',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => '<< В главное меню',
                                      'callback_data' => '/combacktostartmatches',
                                  ),
                              ),
                            ),
                        )),
                      );
                      break;
                    }
                  }
                  //Если ЗЗ установлен
                  elseif ($resultStatusZodiac->num_rows != 0) {
                    if ($statusZodiac['zodiac_sign'] == 'Овен') {
                      $sign_emoticon = "♈️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Телец') {
                      $sign_emoticon = "♉️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Близнецы') {
                      $sign_emoticon = "♊️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Рак') {
                      $sign_emoticon = "♋️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Лев') {
                      $sign_emoticon = "♌️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Дева') {
                      $sign_emoticon = "♍️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Весы') {
                      $sign_emoticon = "♎️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Скорпион') {
                      $sign_emoticon = "♏️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Стрелец') {
                      $sign_emoticon = "♐️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Козерог') {
                      $sign_emoticon = "♑️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Водолей') {
                      $sign_emoticon = "♒️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Рыбы') {
                      $sign_emoticon = "♓️";
                    }
                    //Если Верификация не пройдена
                    if ($statusVerification ['result'] == 2 || $statusVerification ['result'] == 0) {
                      $getQuery = array(
                          "chat_id" => $chat_id,
                          "message_id" => $message_id['message_id'],
                          "text" => 'Моя анкета:',
                          'reply_markup' => json_encode(array(
                              'inline_keyboard' => array(
                                  // array(
                                  //     array(
                                  //         'text' => 'Верификация: ✖️',
                                  //         'callback_data' => '/verification',
                                  //     ),
                                  // ),
                                  array(
                                    array(
                                        'text' => 'Указать учебное заведение:',
                                        'callback_data' => '/study',
                                    ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Soul Mate тест: ✅',
                                          'callback_data' => '/soulmatetest',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Знак зодиака: '.$sign_emoticon,
                                          'callback_data' => '/zodiacsign',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Редактировать мою анкету',
                                          'callback_data' => '/register',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Показать мою анкету',
                                          'callback_data' => '/showprofile',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => '<< В главное меню',
                                          'callback_data' => '/combacktostartmatches',
                                      ),
                                  ),
                              ),
                          )),
                      );
                      break;
                    }
                    //Если Верификация пройдена
                    if ($statusVerification ['result'] == 1) {
                      $getQuery = array(
                          "chat_id" => $chat_id,
                          "message_id" => $message_id['message_id'],
                          "text" => 'Моя анкета:',
                          'reply_markup' => json_encode(array(
                              'inline_keyboard' => array(
                                  // array(
                                  //     array(
                                  //         'text' => 'Верификация: ✅',
                                  //         'callback_data' => '/verification',
                                  //     ),
                                  // ),
                                  array(
                                    array(
                                        'text' => 'Указать учебное заведение:',
                                        'callback_data' => '/study',
                                    ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Soul Mate тест: ✅',
                                          'callback_data' => '/soulmatetest',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Знак зодиака: '.$sign_emoticon,
                                          'callback_data' => '/zodiacsign',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Редактировать мою анкету',
                                          'callback_data' => '/register',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Показать мою анкету',
                                          'callback_data' => '/showprofile',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => '<< В главное меню',
                                          'callback_data' => '/combacktostartmatches',
                                      ),
                                  ),
                              ),
                          )),
                      );
                      break;
                    }
                  }
              }
              //Если SM не пройден
              else {
                  ////Если ЗЗ не установлен
                  if ($resultStatusZodiac->num_rows == 0) {
                    //Если Верификация не пройдена
                    if ($statusVerification ['result'] == 2 || $statusVerification ['result'] == 0) {
                      $getQuery = array(
                          "chat_id" => $chat_id,
                          "message_id" => $message_id['message_id'],
                          "text" => 'Моя анкета:',
                          'reply_markup' => json_encode(array(
                              'inline_keyboard' => array(
                                  // array(
                                  //     array(
                                  //         'text' => 'Верификация: ✖️',
                                  //         'callback_data' => '/verification',
                                  //     ),
                                  // ),
                                  array(
                                    array(
                                        'text' => 'Указать учебное заведение:',
                                        'callback_data' => '/study',
                                    ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Soul Mate тест: ✖️',
                                          'callback_data' => '/soulmatetest',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Знак зодиака: ✖️',
                                          'callback_data' => '/zodiacsign',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Редактировать мою анкету',
                                          'callback_data' => '/register',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Показать мою анкету',
                                          'callback_data' => '/showprofile',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => '<< В главное меню',
                                          'callback_data' => '/combacktostartmatches',
                                      ),
                                  ),
                              ),
                          )),
                      );
                      break;
                    }
                    //Если Верификация пройдена
                    if ($statusVerification ['result'] == 1) {
                      $getQuery = array(
                          "chat_id" => $chat_id,
                          "message_id" => $message_id['message_id'],
                          "text" => 'Моя анкета:',
                          'reply_markup' => json_encode(array(
                              'inline_keyboard' => array(
                                  // array(
                                  //     array(
                                  //         'text' => 'Верификация: ✅',
                                  //         'callback_data' => '/verification',
                                  //     ),
                                  // ),
                                  array(
                                    array(
                                        'text' => 'Указать учебное заведение:',
                                        'callback_data' => '/study',
                                    ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Soul Mate тест: ✖️',
                                          'callback_data' => '/soulmatetest',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Знак зодиака: ✖️',
                                          'callback_data' => '/zodiacsign',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Редактировать мою анкету',
                                          'callback_data' => '/register',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => 'Показать мою анкету',
                                          'callback_data' => '/showprofile',
                                      ),
                                  ),
                                  array(
                                      array(
                                          'text' => '<< В главное меню',
                                          'callback_data' => '/combacktostartmatches',
                                      ),
                                  ),
                              ),
                          )),
                      );
                      break;
                    }
                  }
                  //Если ЗЗ установлен
                  elseif ($resultStatusZodiac->num_rows != 0) {
                    if ($statusZodiac['zodiac_sign'] == 'Овен') {
                      $sign_emoticon = "♈️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Телец') {
                      $sign_emoticon = "♉️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Близнецы') {
                      $sign_emoticon = "♊️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Рак') {
                      $sign_emoticon = "♋️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Лев') {
                      $sign_emoticon = "♌️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Дева') {
                      $sign_emoticon = "♍️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Весы') {
                      $sign_emoticon = "♎️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Скорпион') {
                      $sign_emoticon = "♏️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Стрелец') {
                      $sign_emoticon = "♐️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Козерог') {
                      $sign_emoticon = "♑️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Водолей') {
                      $sign_emoticon = "♒️";
                    } elseif ($statusZodiac['zodiac_sign'] == 'Рыбы') {
                      $sign_emoticon = "♓️";
                    }
                    //Если Верификация не пройдена
                    if ($statusVerification ['result'] == 2 || $statusVerification ['result'] == 0) {
                      $getQuery = array(
                        "chat_id" => $chat_id,
                        "message_id" => $message_id['message_id'],
                        "text" => 'Моя анкета:',
                        'reply_markup' => json_encode(array(
                            'inline_keyboard' => array(
                              //   array(
                              //       array(
                              //           'text' => 'Верификация: ✖️',
                              //           'callback_data' => '/verification',
                              //       ),
                              //   ),
                              array(
                                array(
                                    'text' => 'Указать учебное заведение:',
                                    'callback_data' => '/study',
                                ),
                              ),
                                array(
                                    array(
                                        'text' => 'Soul Mate тест: ✖️',
                                        'callback_data' => '/soulmatetest',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Знак зодиака: '.$sign_emoticon,
                                        'callback_data' => '/zodiacsign',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Редактировать мою анкету',
                                        'callback_data' => '/register',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Показать мою анкету',
                                        'callback_data' => '/showprofile',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => '<< В главное меню',
                                        'callback_data' => '/combacktostartmatches',
                                    ),
                                ),
                            ),
                        )),
                    );
                    break;
                    }
                    //Если Верификация пройдена
                    if ($statusVerification ['result'] == 1) {
                      $getQuery = array(
                        "chat_id" => $chat_id,
                        "message_id" => $message_id['message_id'],
                        "text" => 'Моя анкета:',
                        'reply_markup' => json_encode(array(
                            'inline_keyboard' => array(
                              //   array(
                              //       array(
                              //           'text' => 'Верификация: ✅',
                              //           'callback_data' => '/verification',
                              //       ),
                              //   ),
                              array(
                                array(
                                    'text' => 'Указать учебное заведение:',
                                    'callback_data' => '/study',
                                ),
                              ),
                                array(
                                    array(
                                        'text' => 'Soul Mate тест: ✖️',
                                        'callback_data' => '/soulmatetest',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Знак зодиака: '.$sign_emoticon,
                                        'callback_data' => '/zodiacsign',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Редактировать мою анкету',
                                        'callback_data' => '/register',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Показать мою анкету',
                                        'callback_data' => '/showprofile',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => '<< В главное меню',
                                        'callback_data' => '/combacktostartmatches',
                                    ),
                                ),
                            ),
                        )),
                    );
                    break;
                    }
                  }
              }
            }
            //Если университет установлен
            else {
              //Если SM пройден
              if ($statusTest ['test_step'] == 10) {
                //Если ЗЗ не установлен
                if ($resultStatusZodiac->num_rows == 0) {
                  //Если Верификация не пройдена
                  if ($statusVerification ['result'] == 2 || $statusVerification ['result'] == 0) {
                    $getQuery = array(
                        "chat_id" => $chat_id,
                        "message_id" => $message_id['message_id'],
                        "text" => 'Моя анкета:',
                        'reply_markup' => json_encode(array(
                            'inline_keyboard' => array(
                                // array(
                                //     array(
                                //         'text' => 'Верификация: ✖️',
                                //         'callback_data' => '/verification',
                                //     ),
                                // ),
                                array(
                                  array(
                                      'text' => 'ВУЗ: '. $university ['university'],
                                      'callback_data' => '/study',
                                  ),
                                ),
                                array(
                                    array(
                                        'text' => 'Soul Mate тест: ✅',
                                        'callback_data' => '/soulmatetest',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Знак зодиака: ✖️',
                                        'callback_data' => '/zodiacsign',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Редактировать мою анкету',
                                        'callback_data' => '/register',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Показать мою анкету',
                                        'callback_data' => '/showprofile',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => '<< В главное меню',
                                        'callback_data' => '/combacktostartmatches',
                                    ),
                                ),
                            ),
                        )),
                    );
                    break;
                  }
                  //Если Верификация пройдена
                  if ($statusVerification ['result'] == 1) {
                  $getQuery = array(
                    "chat_id" => $chat_id,
                    "message_id" => $message_id['message_id'],
                    "text" => 'Моя анкета:',
                    'reply_markup' => json_encode(array(
                        'inline_keyboard' => array(
                            // array(
                            //     array(
                            //         'text' => 'Верификация: ✅',
                            //         'callback_data' => '/verification',
                            //     ),
                            // ),
                            array(
                              array(
                                  'text' => 'ВУЗ: '. $university ['university'],
                                  'callback_data' => '/study',
                              ),
                            ),
                            array(
                                array(
                                    'text' => 'Soul Mate тест: ✅',
                                    'callback_data' => '/soulmatetest',
                                ),
                            ),
                            array(
                                array(
                                    'text' => 'Знак зодиака: ✖️',
                                    'callback_data' => '/zodiacsign',
                                ),
                            ),
                            array(
                                array(
                                    'text' => 'Редактировать мою анкету',
                                    'callback_data' => '/register',
                                ),
                            ),
                            array(
                                array(
                                    'text' => 'Показать мою анкету',
                                    'callback_data' => '/showprofile',
                                ),
                            ),
                            array(
                                array(
                                    'text' => '<< В главное меню',
                                    'callback_data' => '/combacktostartmatches',
                                ),
                            ),
                          ),
                      )),
                    );
                    break;
                  }
                }
                //Если ЗЗ установлен
                elseif ($resultStatusZodiac->num_rows != 0) {
                  if ($statusZodiac['zodiac_sign'] == 'Овен') {
                    $sign_emoticon = "♈️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Телец') {
                    $sign_emoticon = "♉️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Близнецы') {
                    $sign_emoticon = "♊️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Рак') {
                    $sign_emoticon = "♋️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Лев') {
                    $sign_emoticon = "♌️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Дева') {
                    $sign_emoticon = "♍️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Весы') {
                    $sign_emoticon = "♎️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Скорпион') {
                    $sign_emoticon = "♏️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Стрелец') {
                    $sign_emoticon = "♐️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Козерог') {
                    $sign_emoticon = "♑️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Водолей') {
                    $sign_emoticon = "♒️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Рыбы') {
                    $sign_emoticon = "♓️";
                  }
                  //Если Верификация не пройдена
                  if ($statusVerification ['result'] == 2 || $statusVerification ['result'] == 0) {
                    $getQuery = array(
                        "chat_id" => $chat_id,
                        "message_id" => $message_id['message_id'],
                        "text" => 'Моя анкета:',
                        'reply_markup' => json_encode(array(
                            'inline_keyboard' => array(
                                // array(
                                //     array(
                                //         'text' => 'Верификация: ✖️',
                                //         'callback_data' => '/verification',
                                //     ),
                                // ),
                                array(
                                  array(
                                      'text' => 'ВУЗ: '. $university ['university'],
                                      'callback_data' => '/study',
                                  ),
                                ),
                                array(
                                    array(
                                        'text' => 'Soul Mate тест: ✅',
                                        'callback_data' => '/soulmatetest',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Знак зодиака: '.$sign_emoticon,
                                        'callback_data' => '/zodiacsign',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Редактировать мою анкету',
                                        'callback_data' => '/register',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Показать мою анкету',
                                        'callback_data' => '/showprofile',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => '<< В главное меню',
                                        'callback_data' => '/combacktostartmatches',
                                    ),
                                ),
                            ),
                        )),
                    );
                    break;
                  }
                  //Если Верификация пройдена
                  if ($statusVerification ['result'] == 1) {
                    $getQuery = array(
                        "chat_id" => $chat_id,
                        "message_id" => $message_id['message_id'],
                        "text" => 'Моя анкета:',
                        'reply_markup' => json_encode(array(
                            'inline_keyboard' => array(
                                // array(
                                //     array(
                                //         'text' => 'Верификация: ✅',
                                //         'callback_data' => '/verification',
                                //     ),
                                // ),
                                array(
                                  array(
                                      'text' => 'ВУЗ: '. $university ['university'],
                                      'callback_data' => '/study',
                                  ),
                                ),
                                array(
                                    array(
                                        'text' => 'Soul Mate тест: ✅',
                                        'callback_data' => '/soulmatetest',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Знак зодиака: '.$sign_emoticon,
                                        'callback_data' => '/zodiacsign',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Редактировать мою анкету',
                                        'callback_data' => '/register',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Показать мою анкету',
                                        'callback_data' => '/showprofile',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => '<< В главное меню',
                                        'callback_data' => '/combacktostartmatches',
                                    ),
                                ),
                            ),
                        )),
                    );
                    break;
                  }
                }
            }
            //Если SM не пройден
            else {
                ////Если ЗЗ не установлен
                if ($resultStatusZodiac->num_rows == 0) {
                  //Если Верификация не пройдена
                  if ($statusVerification ['result'] == 2 || $statusVerification ['result'] == 0) {
                    $getQuery = array(
                        "chat_id" => $chat_id,
                        "message_id" => $message_id['message_id'],
                        "text" => 'Моя анкета:',
                        'reply_markup' => json_encode(array(
                            'inline_keyboard' => array(
                                // array(
                                //     array(
                                //         'text' => 'Верификация: ✖️',
                                //         'callback_data' => '/verification',
                                //     ),
                                // ),
                                array(
                                  array(
                                      'text' => 'ВУЗ: '. $university ['university'],
                                      'callback_data' => '/study',
                                  ),
                                ),
                                array(
                                    array(
                                        'text' => 'Soul Mate тест: ✖️',
                                        'callback_data' => '/soulmatetest',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Знак зодиака: ✖️',
                                        'callback_data' => '/zodiacsign',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Редактировать мою анкету',
                                        'callback_data' => '/register',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Показать мою анкету',
                                        'callback_data' => '/showprofile',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => '<< В главное меню',
                                        'callback_data' => '/combacktostartmatches',
                                    ),
                                ),
                            ),
                        )),
                    );
                    break;
                  }
                  //Если Верификация пройдена
                  if ($statusVerification ['result'] == 1) {
                    $getQuery = array(
                        "chat_id" => $chat_id,
                        "message_id" => $message_id['message_id'],
                        "text" => 'Моя анкета:',
                        'reply_markup' => json_encode(array(
                            'inline_keyboard' => array(
                                // array(
                                //     array(
                                //         'text' => 'Верификация: ✅',
                                //         'callback_data' => '/verification',
                                //     ),
                                // ),
                                array(
                                  array(
                                      'text' => 'ВУЗ: '. $university ['university'],
                                      'callback_data' => '/study',
                                  ),
                                ),
                                array(
                                    array(
                                        'text' => 'Soul Mate тест: ✖️',
                                        'callback_data' => '/soulmatetest',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Знак зодиака: ✖️',
                                        'callback_data' => '/zodiacsign',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Редактировать мою анкету',
                                        'callback_data' => '/register',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Показать мою анкету',
                                        'callback_data' => '/showprofile',
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => '<< В главное меню',
                                        'callback_data' => '/combacktostartmatches',
                                    ),
                                ),
                            ),
                        )),
                    );
                    break;
                  }
                }
                //Если ЗЗ установлен
                elseif ($resultStatusZodiac->num_rows != 0) {
                  if ($statusZodiac['zodiac_sign'] == 'Овен') {
                    $sign_emoticon = "♈️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Телец') {
                    $sign_emoticon = "♉️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Близнецы') {
                    $sign_emoticon = "♊️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Рак') {
                    $sign_emoticon = "♋️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Лев') {
                    $sign_emoticon = "♌️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Дева') {
                    $sign_emoticon = "♍️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Весы') {
                    $sign_emoticon = "♎️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Скорпион') {
                    $sign_emoticon = "♏️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Стрелец') {
                    $sign_emoticon = "♐️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Козерог') {
                    $sign_emoticon = "♑️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Водолей') {
                    $sign_emoticon = "♒️";
                  } elseif ($statusZodiac['zodiac_sign'] == 'Рыбы') {
                    $sign_emoticon = "♓️";
                  }
                  //Если Верификация не пройдена
                  if ($statusVerification ['result'] == 2 || $statusVerification ['result'] == 0) {
                    $getQuery = array(
                      "chat_id" => $chat_id,
                      "message_id" => $message_id['message_id'],
                      "text" => 'Моя анкета:',
                      'reply_markup' => json_encode(array(
                          'inline_keyboard' => array(
                            //   array(
                            //       array(
                            //           'text' => 'Верификация: ✖️',
                            //           'callback_data' => '/verification',
                            //       ),
                            //   ),
                            array(
                              array(
                                  'text' => 'ВУЗ: '. $university ['university'],
                                  'callback_data' => '/study',
                              ),
                            ),
                              array(
                                  array(
                                      'text' => 'Soul Mate тест: ✖️',
                                      'callback_data' => '/soulmatetest',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => 'Знак зодиака: '.$sign_emoticon,
                                      'callback_data' => '/zodiacsign',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => 'Редактировать мою анкету',
                                      'callback_data' => '/register',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => 'Показать мою анкету',
                                      'callback_data' => '/showprofile',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => '<< В главное меню',
                                      'callback_data' => '/combacktostartmatches',
                                  ),
                              ),
                          ),
                      )),
                  );
                  break;
                  }
                  //Если Верификация пройдена
                  if ($statusVerification ['result'] == 1) {
                    $getQuery = array(
                      "chat_id" => $chat_id,
                      "message_id" => $message_id['message_id'],
                      "text" => 'Моя анкета:',
                      'reply_markup' => json_encode(array(
                          'inline_keyboard' => array(
                            //   array(
                            //       array(
                            //           'text' => 'Верификация: ✅',
                            //           'callback_data' => '/verification',
                            //       ),
                            //   ),
                            array(
                              array(
                                  'text' => 'ВУЗ: '. $university ['university'],
                                  'callback_data' => '/study',
                              ),
                            ),
                              array(
                                  array(
                                      'text' => 'Soul Mate тест: ✖️',
                                      'callback_data' => '/soulmatetest',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => 'Знак зодиака: '.$sign_emoticon,
                                      'callback_data' => '/zodiacsign',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => 'Редактировать мою анкету',
                                      'callback_data' => '/register',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => 'Показать мою анкету',
                                      'callback_data' => '/showprofile',
                                  ),
                              ),
                              array(
                                  array(
                                      'text' => '<< В главное меню',
                                      'callback_data' => '/combacktostartmatches',
                                  ),
                              ),
                          ),
                      )),
                  );
                  break;
                  }
                }
              }
            }

//Главное меню
        case 3:
          $getQuery = array(
            "chat_id" => $chat_id,
            "message_id" => $message_id['message_id'],
            "text" => 'Главное меню:',
            'disable_notification' => true,
            'reply_markup' => json_encode(array(
              'inline_keyboard' => array(
                array(
                  array(
                    'text' => 'Поиск 🔎',
                    'callback_data' => '/startmatch',
                  ),
                  array(
                    'text' => 'Фильтр',
                    'callback_data' => '/filter',
                  ),
                ),
                array(
                  array(
                    'text' => 'Пары',
                    'callback_data' => '/matchmenu',
                  ),
                ),
                array(
                  array(
                    'text' => 'Моя анкета',
                    'callback_data' => '/myprofilemenu',
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
                                'callback_data' => '/filter',
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
    //Команда исправления
    if ($text == '/start') {
      sendTelegramMessage($token, $chat_id, "Главное меню", 1, $mysqli);
    }
    //Главное меню
    if ($showFlag ['main_menu_flag'] == true || isset($showFlag ['main_menu_flag']) == false) {
        //Для первого запуска бота
        if ($text == '/begin' && isset($showFlag ['main_menu_flag']) == false) {
          deleteMenu($chat_id, $token, $mysqli);
          sendTelegramMessage($token, $chat_id, 'Привет, давай создадим твою анкету.', 1, $mysqli);
          return;
        }
        //Запуск главного меню
        elseif ($text == '/start' && isset($showFlag ['main_menu_flag']) == true) {
            deleteMenu($chat_id, $token, $mysqli);
            sendTelegramMessage($token, $chat_id, 'Главное меню:', 1, $mysqli);
            $sqlFilter = ("UPDATE users SET main_menu_flag = true WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            return;
        }
        elseif ($text == '/start' && isset($showFlag ['main_menu_flag']) == false) {
          sendTelegramMessage($token, $chat_id, 'Привет, давай создадим твою анкету.', 1, $mysqli);
          return;
        }
        elseif (($text == '/filter' || $text == 'Фильтр') && isset($showFlag ['main_menu_flag']) == true) {
            $sqlFilter = ("UPDATE users SET filter_flag = true, main_menu_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            editTelegramMessage($token, $chat_id, 0, $mysqli);
            return;
        }
        elseif (($text == '/matchmenu' || $text == 'Пары') && isset($showFlag ['main_menu_flag']) == true) {
            $sqlFilter = ("UPDATE users SET match_menu_flag = true, main_menu_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            editTelegramMessage($token, $chat_id, 1, $mysqli);
            return;
        }
        elseif (($text == '/myprofilemenu' || $text == 'Моя анкета') && isset($showFlag ['main_menu_flag']) == true) {
            $sqlFilter = ("UPDATE users SET my_profile_menu_flag = true, main_menu_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            editTelegramMessage($token, $chat_id, 2, $mysqli);
            return;
        }
        elseif (($text == '/register' || $text == 'Зарегистрироваться' || $text == 'Редактировать мою анкету') && isset($showFlag ['main_menu_flag']) == false) {
            $sqlRating = "INSERT INTO rating_users (chat_id, rating, count_dislike, verification_bonus, zodiac_bonus, status_show) VALUES ('$chat_id', '500', '0',
            'false', 'false', '0')";
            $mysqli->query($sqlRating);
            $sqlFilter = ("UPDATE users SET main_menu_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            deleteMenu($chat_id, $token, $mysqli);
            $sqlCheckReg = "SELECT * FROM users WHERE chat_id = '$chat_id'";
            $resultCheck = $mysqli->query($sqlCheckReg);
            if ($resultCheck->num_rows == 0) {
                $sqlNewReg = "INSERT INTO users (chat_id, username, show_flag, coming_flag, filter_flag, filter_location,
                                                 favorite_age_min, favorite_age_max, filter_age_flag, filter_gender_flag, main_menu_flag, match_menu_flag, my_profile_menu_flag, zodiac_flag)
                                        VALUES ('$chat_id', '$username', 'false', 'false', 'false', 'local', '18', '25', 'false', 'false', 'false',
                                                'false', 'false', 'false')";
                $mysqli->query($sqlNewReg);
            }
            $sqlFilter = ("UPDATE users SET main_menu_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            registerStep_1($token, $chat_id, $mysqli);
            return;
        }
        elseif (($text == '/startmatch' || $text == 'Поиск') && isset($showFlag ['main_menu_flag']) == true) {
            $sqlCheckCountUsers = "SELECT * FROM users";
            $resultCheckCountUsers = $mysqli->query($sqlCheckCountUsers);
            if ($resultCheckCountUsers->num_rows >= 50) {
                $sqlFilter = ("UPDATE users SET main_menu_flag = false WHERE chat_id = '$chat_id'");
                $mysqli->query($sqlFilter);
                deleteMenu($chat_id, $token, $mysqli);
                showAlgorithm ($token, $chat_id, $mysqli);
                return;
            }
            else {
                sendTelegramMessage($token, $chat_id, 'К сожалению в боте ещё мало анкет:( Поделись ссылкой с друзьями -> https://t.me/hook_app_bot, чтобы поиск открылся быстрее!', 0, $mysqli);
                return;
            }
        }
        else {
            sendTelegramMessage($token, $chat_id, 'Неверная команда', 0, $mysqli);
            return;
        }
    }
    //Меню Пары
    elseif ($showFlag['match_menu_flag'] == true) {
        if ($text == '/matches' || $text == 'Ваши пары') {
            	deleteMenu($chat_id, $token, $mysqli);
            	showMatches ($token, $chat_id, $mysqli);
                return;
            }
        elseif ($text == '/checklike' || $text == 'Анкеты которым вы понравились') {
            $sqlFlag = ("UPDATE users SET match_menu_flag = false  WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFlag);
            deleteMenu($chat_id, $token, $mysqli);
            comingLikes ($token, $chat_id, $mysqli);
            return;
        }
        elseif ($text == '/combacktostartmenu') {
            $sqlFlag = ("UPDATE users SET main_menu_flag = true, match_menu_flag = false  WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFlag);
            editTelegramMessage($token, $chat_id, 3, $mysqli);
            return;
        }
        else {
            sendTelegramMessage ($token, $chat_id, 'Неверная команда', 0, $mysqli);
            return;
        }

    }
    //Меню Моя анкета
    elseif ($showFlag['my_profile_menu_flag'] == true) {
        if ($text == '/showprofile' || $text == 'Показать мою анкету') {
            deleteMenu($chat_id, $token, $mysqli);
            showProfile ($token, $chat_id, $chat_id, $mysqli);
            sendTelegramMessage ($token, $chat_id, 'Моя анкета:', 6, $mysqli);
            return;
        }
        elseif ($text == '/study' || $text == 'ВУЗ') {
          $sqlFilter = ("UPDATE users SET study_flag = true WHERE chat_id = '$chat_id'");
          $mysqli->query($sqlFilter);
          deleteMenu($chat_id, $token, $mysqli);
          sendTelegramMessage($token, $chat_id, 'Выберите ВУЗ:', 6.1, $mysqli);
          return;
        }
        elseif ($text == '/verification' || $text == 'Верификация') {
          $sqlFilter = ("UPDATE users SET verification_flag = true WHERE chat_id = '$chat_id'");
          $mysqli->query($sqlFilter);
          deleteMenu($chat_id, $token, $mysqli);
          $sqlCheck = ("SELECT * FROM verification_users WHERE chat_id = '$chat_id'");
          $resultSqlCheck = $mysqli->query($sqlCheck);
          $resultAssoc = $resultSqlCheck->fetch_assoc();
          $result = $resultAssoc ['result'];
          $countFingers = rand(1, 5);
          if ($resultSqlCheck->num_rows != 0 && $result == 1) { // Профиль уже подтверждён
            sendTelegramMessage ($token, $chat_id, 'Ваш профиль уже подтверждён', 0, $mysqli);
            $sqlFilter = ("UPDATE users SET verification_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            deleteMenu($chat_id, $token, $mysqli);
            sendTelegramMessage ($token, $chat_id, 'Моя анкета:', 6, $mysqli);
            return;
          }
          elseif ($resultSqlCheck->num_rows != 0 && $result == 0) { // Профиль ожидает проверки
            deleteMenu($chat_id, $token, $mysqli);
            sendTelegramMessage ($token, $chat_id, 'Ваше фото уже отправлено на проверку', 0, $mysqli);
            $sqlFilter = ("UPDATE users SET verification_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            sendTelegramMessage ($token, $chat_id, 'Моя анкета:', 6, $mysqli);
            return;
          }
          elseif ($resultSqlCheck->num_rows != 0 && $result == 2) { // Профиль не прошёл проверку
            deleteMenu($chat_id, $token, $mysqli);
            sendTelegramMessage ($token, $chat_id, 'Ваше отправленное фото не прошло проверку, попробуйте ещё раз', 0, $mysqli);
            $sqlCheck = ("UPDATE verification_users SET count_fingers = $countFingers WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlCheck);
            $caption = 'Отправьте фото с данным жестом';
            switch ($countFingers) {
              case 1:
                $photo_1 = 'AgACAgIAAxkBAAJBa2Vc6vhq6jTpFpBbLh_6_1X78hj-AAJk0jEbgLnpSgu_6kByKL4KAQADAgADeAADMwQ';
                $arrayQuery = [
                  'chat_id' => $chat_id,
                  'disable_notification' => true,
                  'reply_markup' => null,
                  'media' => json_encode([
                  ['type' => 'photo', 'media' => $photo_1, 'caption' => $caption ],
                  ])
                ];
                break;
              case 2:
                $photo_2 = 'AgACAgIAAxkBAAJBbGVc61jCdDkyx3SP1mHsLnrwCbl0AAI72DEbXn3pSsiopBXnvtcKAQADAgADeQADMwQ';
                $arrayQuery = [
                  'chat_id' => $chat_id,
                  'disable_notification' => true,
                  'reply_markup' => null,
                  'media' => json_encode([
                  ['type' => 'photo', 'media' => $photo_2, 'caption' => $caption ],
                  ])
                ];
                break;
              case 3:
                $photo_3 = 'AgACAgIAAxkBAAJBbWVc64FQ_dJgh0AqJ9u7npezcIcMAAI82DEbXn3pSjyAyhTqnQtZAQADAgADeAADMwQ';
                $arrayQuery = [
                  'chat_id' => $chat_id,
                  'disable_notification' => true,
                  'reply_markup' => null,
                  'media' => json_encode([
                  ['type' => 'photo', 'media' => $photo_3, 'caption' => $caption ],
                  ])
                ];
                break;
              case 4:
                $photo_4 = 'AgACAgIAAxkBAAJBRWVc59TPZbCtGLhz-I7fRqsHLJEVAAI-2DEbXn3pSuPd5q5vBB7CAQADAgADeAADMwQ';
                $arrayQuery = [
                  'chat_id' => $chat_id,
                  'disable_notification' => true,
                  'reply_markup' => null,
                  'media' => json_encode([
                  ['type' => 'photo', 'media' => $photo_4, 'caption' => $caption ],
                  ])
                ];
                break;
              case 5:
                $photo_5 = 'AgACAgIAAxkBAAJBWGVc6A4vMDrP7gitDf6cJAne_ddPAAI_2DEbXn3pSt9q4EoqhSsZAQADAgADeQADMwQ';
                $arrayQuery = [
                  'chat_id' => $chat_id,
                  'disable_notification' => true,
                  'reply_markup' => null,
                  'media' => json_encode([
                  ['type' => 'photo', 'media' => $photo_5, 'caption' => $caption ],
                  ])
                ];
                break;
            }
            $ch = curl_init('https://api.telegram.org/bot'. $token .'/sendMediaGroup');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $arrayQuery);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_exec($ch);
            curl_close($ch);
            return;
          }
          else { //Первая верификация
            $sqlCheck = ("INSERT INTO verification_users (chat_id, count_fingers) VALUES ('$chat_id', '$countFingers')");
            $mysqli->query($sqlCheck);
            $caption = 'Отправьте фото с данным жестом';
            switch ($countFingers) {
              case 1:
                $photo_1 = 'AgACAgIAAxkBAAJBa2Vc6vhq6jTpFpBbLh_6_1X78hj-AAJk0jEbgLnpSgu_6kByKL4KAQADAgADeAADMwQ';
                $arrayQuery = [
                  'chat_id' => $chat_id,
                  'disable_notification' => true,
                  'reply_markup' => null,
                  'media' => json_encode([
                  ['type' => 'photo', 'media' => $photo_1, 'caption' => $caption ],
                  ])
                ];
                break;
              case 2:
                $photo_2 = 'AgACAgIAAxkBAAJBbGVc61jCdDkyx3SP1mHsLnrwCbl0AAI72DEbXn3pSsiopBXnvtcKAQADAgADeQADMwQ';
                $arrayQuery = [
                  'chat_id' => $chat_id,
                  'disable_notification' => true,
                  'reply_markup' => null,
                  'media' => json_encode([
                  ['type' => 'photo', 'media' => $photo_2, 'caption' => $caption ],
                  ])
                ];
                break;
              case 3:
                $photo_3 = 'AgACAgIAAxkBAAJBbWVc64FQ_dJgh0AqJ9u7npezcIcMAAI82DEbXn3pSjyAyhTqnQtZAQADAgADeAADMwQ';
                $arrayQuery = [
                  'chat_id' => $chat_id,
                  'disable_notification' => true,
                  'reply_markup' => null,
                  'media' => json_encode([
                  ['type' => 'photo', 'media' => $photo_3, 'caption' => $caption ],
                  ])
                ];
                break;
              case 4:
                $photo_4 = 'AgACAgIAAxkBAAJBRWVc59TPZbCtGLhz-I7fRqsHLJEVAAI-2DEbXn3pSuPd5q5vBB7CAQADAgADeAADMwQ';
                $arrayQuery = [
                  'chat_id' => $chat_id,
                  'disable_notification' => true,
                  'reply_markup' => null,
                  'media' => json_encode([
                  ['type' => 'photo', 'media' => $photo_4, 'caption' => $caption ],
                  ])
                ];
                break;
              case 5:
                $photo_5 = 'AgACAgIAAxkBAAJBWGVc6A4vMDrP7gitDf6cJAne_ddPAAI_2DEbXn3pSt9q4EoqhSsZAQADAgADeQADMwQ';
                $arrayQuery = [
                  'chat_id' => $chat_id,
                  'disable_notification' => true,
                  'reply_markup' => null,
                  'media' => json_encode([
                  ['type' => 'photo', 'media' => $photo_5, 'caption' => $caption ],
                  ])
                ];
                break;
            }
            $ch = curl_init('https://api.telegram.org/bot'. $token .'/sendMediaGroup');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $arrayQuery);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_exec($ch);
            curl_close($ch);
          }
        }
        elseif ($text == '/zodiacsign' || $text == 'Знак зодиака') {
            $sqlFilter = ("UPDATE users SET zodiac_flag = true WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            deleteMenu($chat_id, $token, $mysqli);
            $sqlCheck = ("SELECT * FROM zodiac_users WHERE chat_id = '$chat_id'");
            $resultSqlCheck = $mysqli->query($sqlCheck);
            if ($resultSqlCheck->num_rows != 0) {
              sendTelegramMessage ($token, $chat_id, "Введите свою дату рождения в формате дд.мм", 0, $mysqli);
              return;
            }
            else {
              $sqlCheck = ("INSERT INTO zodiac_users (chat_id) VALUES ('$chat_id')");
              $mysqli->query($sqlCheck);
              sendTelegramMessage ($token, $chat_id, "Введите свою дату рождения в формате дд.мм", 0, $mysqli);
              return;
            }
        }
        elseif ($text == '/soulmatetest' || $text == 'Soul Mate тест') {
            $sqlFilter = ("UPDATE users SET test_flag = true WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            deleteMenu($chat_id, $token, $mysqli);
            testStep_1 ($token, $chat_id, $mysqli);
            return;
        }
        elseif ($text == '/register' || $text == 'Зарегистрироваться' || $text == 'Редактировать мою анкету') {
            deleteMenu($chat_id, $token, $mysqli);
            $sqlCheckReg = "SELECT * FROM users WHERE chat_id = '$chat_id'";
            $resultCheck = $mysqli->query($sqlCheckReg);
            if ($resultCheck->num_rows == 0) {
                $sqlNewReg =
                "INSERT INTO users
                (latitude, longitude, chat_id, username, description, show_flag, coming_flag, filter_flag, filter_location, favorite_age_min, favorite_age_max, filter_age_flag, filter_gender_flag, test_flag, match_menu_flag, my_profile_menu_flag, main_menu_flag, video_1, video_2, video_3,  zodiac_flag, verification_flag)
                VALUES
                (NULL, NULL, '$chat_id', '$username', NULL, 'false', 'false', 'false', 'local', '18', '25', 'false', 'false', 'false', 'false', 'false', 'false',
                'false', 'false', 'false', 'false', 'false')";
                $mysqli->query($sqlNewReg);
            }
            else {
              $sqlNewReg = "UPDATE users SET  username = '$username',
                                              description = NULL,
                                              show_flag = 'false',
                                              coming_flag = 'false',
                                              filter_flag = 'false',
                                              filter_location = 'local',
                                              favorite_age_min = '18',
                                              favorite_age_max = '25',
                                              filter_age_flag = 'false',
                                              filter_gender_flag = 'false',
                                              test_flag = 'false',
                                              latitude = NULL,
                                              longitude = NULL,
                                              match_menu_flag = 'false',
                                              my_profile_menu_flag = 'false',
                                              main_menu_flag = 'false',
                                              video_1 = 'false',
                                              video_2 = 'false',
                                              video_3 = 'false',
                                              zodiac_flag = 'false',
                                              verification_flag = 'false'
                              WHERE chat_id = '$chat_id'";
              $mysqli->query($sqlNewReg);
            }
            registerStep_1($token, $chat_id, $mysqli);
            return;
        }
        elseif ($text == '/combacktostartmatches') {
            $sqlFlag = ("UPDATE users SET main_menu_flag = true, my_profile_menu_flag = false  WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFlag);
            editTelegramMessage($token, $chat_id, 3, $mysqli);
            return;
        }
        elseif ($showFlag ['study_flag'] == true) {
          if ($text == 'Удалить') {
            $sqlCheck = ("UPDATE users SET university = NULL, study_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlCheck);
            sendTelegramMessage ($token, $chat_id, 'Моя анкета:', 6, $mysqli);
            return;
          }
          else {
            if ($text != 'ЮУрГУ' && $text != 'UMED' && $text != 'ЮУрГГПУ') {
              sendTelegramMessage($token, $chat_id, "Выберите правильный ВУЗ", 6.1, $mysqli);
              return;
            }
            $sqlCheck = ("UPDATE users SET university = '$text', study_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlCheck);
            sendTelegramMessage ($token, $chat_id, 'Моя анкета:', 6, $mysqli);
            return;
          }
        }
        elseif ($showFlag ['zodiac_flag'] == true) {
          if (isValidDate($text)) {
            list($day, $month) = explode(".", $text);
            $sign = determineZodiacSign ($day, $month);
            $sqlZodiac = ("UPDATE zodiac_users SET zodiac_sign = '$sign', date_of_birth = '$text' WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlZodiac);
            $sqlFilter = ("UPDATE users SET zodiac_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            ratingChange ($chat_id, $mysqli, 5);
            sendTelegramMessage ($token, $chat_id, 'Моя анкета:', 6, $mysqli);
            return;
          }
          else{
            sendTelegramMessage ($token, $chat_id, "Введите свою дату рождения в формате дд.мм", 0, $mysqli);
            return;
          }
        }
        elseif ($showFlag ['verification_flag'] == true) {
          if (isset($file_id)) {
            $sqlSetPhoto = ("UPDATE verification_users SET check_image = '$file_id', result = '0' WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlSetPhoto);
            $sqlFilter = ("UPDATE users SET verification_flag = false WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            sendTelegramMessage ($token, $chat_id, "Ваша фотка отправлена на проверку", 0, $mysqli);
            sendTelegramMessage ($token, $chat_id, 'Моя анкета:', 6, $mysqli);
            return;
          }
          else {
            sendTelegramMessage ($token, $chat_id, "Отправьте фото", 0, $mysqli);
            return;
          }

        }
        else {
          sendTelegramMessage ($token, $chat_id, 'Неверная команда', 0, $mysqli);
          return;
        }
    }
    //Меню Фильтр
    elseif ($showFlag['filter_flag'] == true) {
        if ($showFlag['filter_age_flag'] == true) {
            $delimiter = "-";
            $parts = explode($delimiter, $text);
            if (($parts [0] >= 18 && $parts [0] < 100) && ($parts [1] >= 18 && $parts [1] < 100) && $parts[0] <= $parts[1] ) {
                $sqlSetFavoriteAge = "UPDATE users SET favorite_age_min = '$parts[0]', favorite_age_max = '$parts[1]' WHERE chat_id = '$chat_id'";
                $mysqli->query($sqlSetFavoriteAge);
                $sqlFilter = ("UPDATE users SET filter_age_flag = false WHERE chat_id = '$chat_id'");
                $mysqli->query($sqlFilter);
                sendTelegramMessage ($token, $chat_id, 'Настройки фильтра показа анкет:', 2, $mysqli);
                return;
            }
            else {
                sendTelegramMessage ($token, $chat_id, 'Введите диапазон возраста в формате (Минимальный-Максимальный). Пример: 18-22', 0, $mysqli);
                return;
            }
        }
        elseif ($showFlag['filter_gender_flag'] == true) {
            if ($text == 'Парни' || $text == 'Девушки' || $text == 'Все') {
                $sqlFilter = ("UPDATE users SET filter_gender_flag = false WHERE chat_id = '$chat_id'");
                $mysqli->query($sqlFilter);
                if ($text == 'Парни') {
                    $sqlReg = ("UPDATE users SET favorite_gender = 'Мужской' WHERE chat_id = '$chat_id'");
                }
                elseif ($text == 'Девушки') {
                    $sqlReg = ("UPDATE users SET favorite_gender = 'Женский' WHERE chat_id = '$chat_id'");
                }
                elseif ($text == 'Все') {
                    $sqlReg = ("UPDATE users SET favorite_gender = 'Все' WHERE chat_id = '$chat_id'");
                }
                $mysqli->query($sqlReg);
                sendTelegramMessage ($token, $chat_id, 'Настройки фильтра показа анкет:', 2, $mysqli);
                return;
            }
            else {
                sendTelegramMessage($token, $chat_id, 'Некорректный ответ', 4, $mysqli);
                return;
            }
        }
        elseif ($showFlag['filter_flag'] == true) {
            if ($text == '/distance') {
                $sqlFilter = ("SELECT filter_location FROM users WHERE chat_id = '$chat_id'");
                $result = $mysqli->query($sqlFilter);
                $filter_location = $result->fetch_assoc();
                if ( $filter_location['filter_location'] == 'local') {
                    $sqlFilter = ("UPDATE users SET filter_location = 'global' WHERE chat_id = '$chat_id'");
                    $mysqli->query($sqlFilter);
                }
                elseif ( $filter_location['filter_location'] == 'global') {
                    $sqlFilter = ("UPDATE users SET filter_location = 'local' WHERE chat_id = '$chat_id'");
                    $mysqli->query($sqlFilter);
                }
                editTelegramMessage($token, $chat_id, 0, $mysqli);
                return;
            }
            elseif ($text == '/age') {
                $sqlFilter = ("UPDATE users SET filter_age_flag = true WHERE chat_id = '$chat_id'");
                $mysqli->query($sqlFilter);
                deleteMenu($chat_id, $token, $mysqli);
                sendTelegramMessage ($token, $chat_id, 'Введите диапазон возраста в формате (Минимальный-Максимальный). Пример: 18-22', 0, $mysqli);
                return;
            }
            elseif ($text == '/favorite_gender') {
                $sqlFilter = ("UPDATE users SET filter_gender_flag = true WHERE chat_id = '$chat_id'");
                $mysqli->query($sqlFilter);
                deleteMenu($chat_id, $token, $mysqli);
                sendTelegramMessage ($token, $chat_id, 'Выберите пол котрый вы ищете:', 4.1, $mysqli);
                return;
            }
            elseif ($text == '/combacktostartmatches') {
                $sqlFilter = ("UPDATE users SET filter_flag = false WHERE chat_id = '$chat_id'");
                $mysqli->query($sqlFilter);
                deleteMenu($chat_id, $token, $mysqli);
                showAlgorithm ($token, $chat_id, $mysqli);
                return;
            }
            elseif ($text == '/combacktostartmenu') {
                $sqlFilter = ("UPDATE users SET filter_flag = false, main_menu_flag = true WHERE chat_id = '$chat_id'");
                $mysqli->query($sqlFilter);
                editTelegramMessage($token, $chat_id, 3, $mysqli);
                return;
            }
            else {
                deleteMenu($chat_id, $token, $mysqli);
                sendTelegramMessage ($token, $chat_id, 'Неверная команда', 0, $mysqli);
                sendTelegramMessage ($token, $chat_id, 'Настройки фильтра показа анкет:', 2, $mysqli);
            }
        }

    }
    //Оценка
    elseif ($showFlag['coming_flag'] == true || $showFlag['show_flag'] == true)  {
        $sqlMatchId = "SELECT last_shown_id FROM users WHERE chat_id = '$chat_id'";
        $resultMatchId = $mysqli->query($sqlMatchId);
        $match_id = $resultMatchId->fetch_assoc();
        if ($text == '❤️') {
            if ($showFlag['show_flag'] == true) {
                $sqlLike = ("UPDATE users SET show_flag = FALSE WHERE chat_id = '$chat_id'");
            }
            elseif ($showFlag['coming_flag'] == true) {
                $sqlLike = ("UPDATE users SET coming_flag = FALSE WHERE chat_id = '$chat_id'");
            }
            $mysqli->query($sqlLike);
            $sqlSearchId = "SELECT * FROM rate WHERE (first_id = '$chat_id' AND second_id = {$match_id['last_shown_id']})
                                                  OR (second_id = '$chat_id' AND first_id = {$match_id['last_shown_id']})";
            $resultSqlSearchId = $mysqli->query($sqlSearchId);
            $matchSearchId = $resultSqlSearchId->fetch_assoc();
            //Изменение рейтинга
            ratingChange ($match_id['last_shown_id'], $mysqli, 2);
            $sqlUpd = ("UPDATE rating_users SET count_dislike = 0 WHERE chat_id = '{$match_id['last_shown_id']}'");
            $mysqli->query($sqlUpd);
            // Если строка с NULL NULL
            if (isset($matchSearchId['first_rate']) == false && isset($matchSearchId['second_rate']) == false) {
                if ($matchSearchId['first_id'] == $chat_id) {
                    $sqlSetLike = ("UPDATE rate SET first_rate = TRUE WHERE id = {$matchSearchId['id']}");
                    $mysqli->query($sqlSetLike);
                }
                elseif ($matchSearchId["second_id"] == $chat_id) {
                    $sqlSetLike = ("UPDATE rate SET second_rate = TRUE WHERE id = {$matchSearchId['id']}");
                    $mysqli->query($sqlSetLike);
                }
                sendTelegramMessage($token, $match_id['last_shown_id'], 'Вы кому то понравились. Проверьте раздел Лайки.', 0, $mysqli);
                if ($showFlag['show_flag'] == true) {
                    showAlgorithm ($token, $chat_id, $mysqli);
                }
                elseif ($showFlag['coming_flag'] == true) {
                    comingLikes($token, $chat_id, $mysqli);
                }
                return;
            }
            //Если строка с SET NULL
            elseif (isset($matchSearchId['first_rate'])) {
                $sqlSetLike = ("UPDATE rate SET second_rate = TRUE WHERE id = {$matchSearchId['id']}");
                $mysqli->query($sqlSetLike);
                if ($matchSearchId['first_rate'] == 1) {
                    $sqlWhereLike = ("SELECT username FROM users WHERE chat_id = {$match_id['last_shown_id']}");
                    $resultUsernameMatch = $mysqli->query($sqlWhereLike);
                    $usernameMatch = $resultUsernameMatch->fetch_assoc();
                    sendTelegramMessage($token, $chat_id, 'Это взаимно! Начинай общение ➤ @'.$usernameMatch['username'], 0, $mysqli);
					sendTelegramMessage($token, $match_id['last_shown_id'], 'У вас появилась новая взаимная симпатия. Проверьте раздел мэтчей.', 0, $mysqli);
                }
                if ($showFlag['show_flag'] == true) {
                    showAlgorithm ($token, $chat_id, $mysqli);
                }
                if ($showFlag['coming_flag'] == true) {
                    comingLikes($token, $chat_id, $mysqli);
                }
                return;
            }
            //Если строка с NULL SET
            elseif (isset($matchSearchId['second_rate'])) {
                $sqlSetLike = ("UPDATE rate SET first_rate = TRUE WHERE id = {$matchSearchId['id']}");
                $mysqli->query($sqlSetLike);
                if ($matchSearchId['second_rate'] == 1) {
                    $sqlWhereLike = ("SELECT username FROM users WHERE chat_id = {$match_id['last_shown_id']}");
                    $resultUsernameMatch = $mysqli->query($sqlWhereLike);
                    $usernameMatch = $resultUsernameMatch->fetch_assoc();
                    sendTelegramMessage($token, $chat_id, 'Это взаимно! Начинай общение ➤ @'.$usernameMatch['username'], 0, $mysqli);
					sendTelegramMessage($token, $match_id['last_shown_id'], 'У вас появилась новая взаимная симпатия. Проверьте раздел мэтчей.', 0, $mysqli);
                }
                if ($showFlag['show_flag'] == true) {
                    showAlgorithm ($token, $chat_id, $mysqli);
                }
                elseif ($showFlag['show_flag'] == true) {
                    comingLikes($token, $chat_id, $mysqli);
                }
                return;
            }
        }
        elseif ($text == '👎') {
            if ($showFlag['show_flag'] == true) {
                $sqlLike = ("UPDATE users SET show_flag = FALSE WHERE chat_id = '$chat_id'");
            }
            elseif ($showFlag['coming_flag'] == true) {
                $sqlLike = ("UPDATE users SET coming_flag = FALSE WHERE chat_id = '$chat_id'");
            }
            $mysqli->query($sqlLike);
            $sqlSearchId = "SELECT * FROM rate WHERE (first_id = '$chat_id' AND second_id = {$match_id['last_shown_id']})
                                                  OR (second_id = '$chat_id' AND first_id = {$match_id['last_shown_id']})";
            $resultSqlSearchId = $mysqli->query($sqlSearchId);
            $matchSearchId = $resultSqlSearchId->fetch_assoc();
            //Измененине рейтинга
            $sqlCountDislike = "SELECT count_dislike FROM rating_users WHERE chat_id = '{$match_id['last_shown_id']}'";
            $resultCountDislike = $mysqli->query($sqlCountDislike);
            $count_dislike = $resultCountDislike->fetch_assoc();
            if ($count_dislike['count_dislike'] >= 5) {
              ratingChange ($match_id['last_shown_id'], $mysqli, 6);
              $sqlReset = ("UPDATE rating_users SET count_dislike = 0 WHERE chat_id = '{$match_id['last_shown_id']}'");
              $mysqli->query($sqlReset);
            }
            else {
              $sqlUpd = ("UPDATE rating_users SET count_dislike = count_dislike + 1 WHERE chat_id = '{$match_id['last_shown_id']}'");
              $mysqli->query($sqlUpd);
            }
            // Если строка с NULL NULL
            if (isset($matchSearchId['first_rate']) == false && isset($matchSearchId['second_rate']) == false) {
                if ($matchSearchId['first_id'] == $chat_id) {
                    $sqlSetLike = ("UPDATE rate SET first_rate = FALSE WHERE id = {$matchSearchId['id']}");
                    $mysqli->query($sqlSetLike);
                }
                elseif ($matchSearchId["second_id"] == $chat_id) {
                    $sqlSetLike = ("UPDATE rate SET second_rate = FALSE WHERE id = {$matchSearchId['id']}");
                    $mysqli->query($sqlSetLike);
                }
                if ($showFlag['show_flag'] == true) {
                    showAlgorithm ($token, $chat_id, $mysqli);
                }
                elseif ($showFlag['coming_flag'] == true) {
                    comingLikes($token, $chat_id, $mysqli);
                }
                return;
            }
             //Если строка с SET NULL
             elseif (isset($matchSearchId['first_rate'])) {
                $sqlSetLike = ("UPDATE rate SET second_rate = FALSE WHERE id = {$matchSearchId['id']}");
                $mysqli->query($sqlSetLike);
                if ($showFlag['show_flag'] == true) {
                    showAlgorithm ($token, $chat_id, $mysqli);
                }
                elseif ($showFlag['coming_flag'] == true) {
                    comingLikes($token, $chat_id, $mysqli);
                }
                return;
            }
              //Если строка с NULL SET
              elseif (isset($matchSearchId['second_rate'])) {
                $sqlSetLike = ("UPDATE rate SET first_rate = FALSE WHERE id = {$matchSearchId['id']}");
                $mysqli->query($sqlSetLike);
                if ($showFlag['show_flag'] == true) {
                    showAlgorithm ($token, $chat_id, $mysqli);
                }
                elseif ($showFlag['coming_flag'] == true) {
                    comingLikes($token, $chat_id, $mysqli);
                }
                return;
            }
        }
        elseif ($text == '↩') {
            if ($showFlag['show_flag'] == true) {
                $sqlLike = ("UPDATE users SET show_flag = FALSE WHERE chat_id = '$chat_id'");
            }
            elseif ($showFlag['coming_flag'] == true) {
                $sqlLike = ("UPDATE users SET coming_flag = FALSE WHERE chat_id = '$chat_id'");
            }
            $mysqli->query($sqlLike);
            $sqlFilter = ("UPDATE users SET main_menu_flag = true WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            sendTelegramMessage ($token, $chat_id, '🏠', 8, $mysqli);
            sendTelegramMessage ($token, $chat_id, 'Главное меню:', 1, $mysqli);
        }
        elseif ($text == '↩️') {
            if ($showFlag['show_flag'] == true) {
                $sqlLike = ("UPDATE users SET show_flag = FALSE WHERE chat_id = '$chat_id'");
            }
            elseif ($showFlag['coming_flag'] == true) {
                $sqlLike = ("UPDATE users SET coming_flag = FALSE WHERE chat_id = '$chat_id'");
            }
            $sqlFilter = ("UPDATE users SET match_menu_flag = true WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            $mysqli->query($sqlLike);
            sendTelegramMessage ($token, $chat_id, '❤️‍🔥', 8, $mysqli);
            sendTelegramMessage ($token, $chat_id, 'Меню пар:', 7, $mysqli);
        }
        elseif ($text == 'Фильтр') {
            $sqlFilter = ("UPDATE users SET filter_flag = TRUE WHERE chat_id = '$chat_id'");
            $mysqli->query($sqlFilter);
            sendTelegramMessage ($token, $chat_id, 'Настройки фильтра показа анкет:', 2, $mysqli);
        }
        else {
            sendTelegramMessage($token, $chat_id, 'Неверная команда', 0, $mysqli);
            sendTelegramMessage ($token, $chat_id, 'Оцените анкету', 10, $mysqli);
            return;
        }
    }

}

$mysqli->close();