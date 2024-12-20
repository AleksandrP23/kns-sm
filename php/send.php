<?php
  // Подключение
  use PHPMailer\PHPMailer\Exception;
  use PHPMailer\PHPMailer\PHPMailer;

  require 'phpmailer/PHPMailer.php';
  require 'phpmailer/Exception.php';
  require 'phpmailer/SMTP.php';

  $mail          = new PHPMailer(true);
  $mail->CharSet = 'UTF-8';
  $mail->setLanguage('ru', 'phpmailer/');

  //Переменные
  $form    = $_POST['form'] ?? null;

  $name    = $_POST['name'] ?? null;
  $phone   = $_POST['phone'] ?? null;
  $email   = $_POST['email'] ?? null;
  $ask     = $_POST['ask'] ?? null;
  $message = $_POST['message'] ?? null;
  $link    = $_POST['link'] ?? null;

  $thanks_url = ($form == 'Скачать каталог наших решений') ? 'thanks.html?type=catalog' : 'thanks';

  if (! empty($form)) $form = '<b>Форма:</b><br>' . $_POST['form'] . '<br><br>';
  if (! empty($name)) $name = '<b>Имя:</b><br>' . $_POST['name'] . '<br><br>';
  if (! empty($phone)) $phone = '<b>Телефон:</b><br>' . $_POST['phone'] . '<br><br>';
  if (! empty($email)) $email = '<b>E-mail:</b><br>' . $_POST['email'] . '<br><br>';
  if (! empty($ask)) $ask = '<b>Ваш вопрос:</b><br>' . $_POST['ask'] . '<br><br>';
  if (! empty($message)) $message = '<b>Сопроводительное сообщение:</b><br>' . $_POST['message'] . '<br><br>';
  if (! empty($link)) $link = '<b>Ссылка на процедуру:</b><br>' . $_POST['link'] . '<br><br>';

  $success = false;
  if (!empty($_POST['t'])){ $success = true; }

  if ($success) {

    try {
      // // Настройки сервера
      // $mail->SMTPDebug = 0;
      // // $mail->isSMTP();
      // $mail->Host       = 'smtp.gmail.com';
      // $mail->SMTPAuth   = true;
      // $mail->Username   = 'ilyr.dev';
      // $mail->Password   = 'qAqvAoKb';
      // $mail->SMTPSecure = 'ssl';
      // $mail->Port       = 465;

      // // Адреса
      // $mail->setFrom('lead@geongroup.ru', 'ГЕОН'); // От кого
      // $mail->addAddress('wp-lead@geongroup.ru'); // Кому

      $mail->isHTML(true);
      $mail->Subject = $_POST['form'];
      $mail->Body    = $form . $name . $phone . $email . $ask . $message . $link;

      file_put_contents(__DIR__ . '/logs.log', 2, FILE_APPEND);

      $real_files_count    = isset($_FILES['userfile']) && $_FILES['userfile'] ? array_reduce($_FILES['userfile']['error'], fn ($prev, $x) => $prev + (int) (! isset($x) || $x != UPLOAD_ERR_NO_FILE), 0) : 0;
      $uploaded_files_urls = [];

      if (! empty($_FILES['userfile']) && $real_files_count) {
        $uploadDir = dirname(__DIR__, 4) . '/uploads/';

        // Ensure the upload directory exists
        if (! is_dir($uploadDir)) {
          if (! mkdir($uploadDir, 0777, true)) {
            die('Failed to create upload directory.');
          }
        }

        $mail->Body .= '<b>Прикрепленные файлы:</b><br>';

        for ($ct = 0, $ctMax = count($_FILES['userfile']['tmp_name']); $ct < $ctMax; $ct++) {
          if ($_FILES['userfile']['error'][ $ct ] != UPLOAD_ERR_OK) continue;

          $ext        = pathinfo($_FILES['userfile']['name'][ $ct ], PATHINFO_EXTENSION);
          $filename   = basename($_FILES['userfile']['name'][ $ct ]);
          $uploadfile = $uploadDir . hash('sha256', $_FILES['userfile']['name'][ $ct ]) . '.' . $ext;

          if (move_uploaded_file($_FILES['userfile']['tmp_name'][ $ct ], $uploadfile)) {
            $fileUrl               = 'https://' . $_SERVER['HTTP_HOST'] . '/uploads/' . basename($uploadfile);
            $uploaded_files_urls[] = $fileUrl;
            $mail->Body .= '<a href="' . $fileUrl . '">' . $filename . '</a><br>';
          } else {
            file_put_contents(__DIR__ . '/logs.log', "FILE ERROR " . count($_FILES['userfile']['tmp_name']) . " - " . print_r($_FILES, 1) . PHP_EOL, FILE_APPEND);

            error_log('Failed to move uploaded file: ' . $_FILES['userfile']['name'][ $ct ]);
            header("Location: thanks.html");
            echo "Error upload file";
            exit;
          }
        }
      }
      file_put_contents(__DIR__ . '/logs.log', 3, FILE_APPEND);

      $mail->send();
      sendWebhooks($uploaded_files_urls);
      header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
      header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
      header("Location: thanks.html");
      echo "OK";
    } catch (Exception $e) {
      echo "Сообщение не может быть отправлено. Ошибка отправки: {$mail->ErrorInfo}";
    }

    //roistat start
    if (isset($form)) {
      $roistatData = array(
        'roistat'         => isset($_COOKIE['roistat_visit']) ? $_COOKIE['roistat_visit'] : 'nocookie',
        'key'             => 'MzM0OTBkZjlhYWExY2Y3YjQxY2U3MTZiYzM1YzJiODQ6MjQ5MTYx',
        'title'           => $form,
        'comment'         => $message,
        'name'            => $name,
        'email'           => $email,
        'phone'           => $phone,
        'is_skip_sending' => '1',
        'fields'          => array(
          'ask'  => $ask,
          'link' => $link
        ),
      );

      file_get_contents("https://cloud.roistat.com/api/proxy/1.0/leads/add?" . http_build_query($roistatData));
    }
    //roistat end
  
  }

  function sendWebhooks($uploaded_files_urls) {
    try {
      $allowed_keys   = [ 'form', 'name', 'phone', 'email', 'ask', 'message', 'link' ];
      $allowed_fields = array_intersect_key($_POST, array_flip($allowed_keys));
      $allowed_fields = array_filter($allowed_fields, fn ($x) => ! empty($x));
      $leadData       = [ 
        'form'     => array_merge(
          array_map(fn ($k, $v)     => [ 'key' => $k, 'value' => $v ], array_keys($allowed_fields), array_values($allowed_fields)),
          [ [ "key" => 'files', 'value' => implode(', ', $uploaded_files_urls) ] ]
        ),
        'utm'      => [ 
          // передаем UTM-метки
          "utm_source"   => $_COOKIE['utm_source'] ?? null,
          "utm_medium"   => $_COOKIE['utm_medium'] ?? null,
          "utm_content"  => $_COOKIE['utm_content'] ?? null,
          "utm_term"     => $_COOKIE['utm_term'] ?? null,
          "utm_campaign" => $_COOKIE['utm_campaign'] ?? null,
        ],
        'clientID' => [ 
          // передаем ID для аналитики
          // Google analytics ClientID
          "roistat"     => $_COOKIE['roistat_visit'] ?? null,
          "gclientid"   => $_COOKIE['gclientid'] ?? null,
          "_gid"        => $_COOKIE['_gid'] ?? null,
          "amcuid"      => $_COOKIE['amcuid'] ?? null,
          // Roistat"
          "_ym_uid"     => $_COOKIE['_ym_uid'] ?? null,
          "_ym_counter" => $_COOKIE['_ym_counter'] ?? null,
        ],
        'host'     => "kns.geongroup.ru",
        // домен вашего сайта (ОБЯЗАТЕЛЬНО)
        // 'token'    => "ec31a4cc-2107-499e-a58a-09b6c8a3c7cd",  сюда вводите токен из настроек сайта на стороне amoCRM (ОБЯЗАТЕЛЬНО)
      ];

      file_put_contents(__DIR__ . '/logs.log', print_r(json_encode($leadData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 1), FILE_APPEND);

      sendToGnzs($leadData);

    } catch (\Throwable $th) {
      file_put_contents(__DIR__ . '/errors.log', print_r($th, 1), FILE_APPEND);
    }
  }

  // функция отправки данных на интеграцию
  function sendToGnzs($leadData) {
    $url     = 'https://webhook.gnzs.ru/ext/site-int/amo/16388802?gnzs_token=ec31a4cc-2107-499e-a58a-09b6c8a3c7cd';
    $headers = [ 'Content-Type: application/json' ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($leadData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    $result   = curl_exec($ch);
    $errors   = curl_error($ch);
    $response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    file_put_contents(__DIR__ . '/res.log', print_r([ $result, $errors, $response ], 1) . PHP_EOL, FILE_APPEND);

    curl_close($ch);
  }

?>