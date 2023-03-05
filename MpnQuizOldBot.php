<?php

namespace app\modules\bot;

use aki\telegram\types\ReplyKeyboardRemove;
use app\models\QuizUsers;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use yii\db\Exception;
use Yii;
use yii\db\Query;
use aki\telegram\Telegram;
use function Symfony\Component\String\b;
use aki\telegram\types\KeyboardButton;
use aki\telegram\types\Contact;


class MpnQuizOldBot extends TelegramBot
{
    // https://api.telegram.org/bot5830382656:AAFVRYjStgi53VHdvHbC0-3dJPxfYw6DroI/setWebhook?url=https://telegrambot.mediapronet.ru/api/mpnquizoldbot
    private $webhookUrl = 'https://telegrambot.mediapronet.ru/api/mpnquizoldbot';

    public function initializationBot()
    {
        $this->token = "5830382656:AAFVRYjStgi53VHdvHbC0-3dJPxfYw6DroI";
        $this->name = 'mpnquizoldbot';
    }


    public function isMessage()
    {
        $id = 0;
        if (!empty($this->input['message']['chat']['id'])) {
            $id = $this->input['message']['chat']['id'];
        }
        if (!empty($this->input['callback_query'])) {
            $id = $this->input['callback_query']['message']['chat']['id'];
        }

        if (isset($this->input['message']['contact'])) {
            $this->replyKeyboardRemove($id);
            $this->quizm($id);
            $phone = $this->input['message']['contact']['phone_number'];
            $name = $this->input['message']['contact']['first_name'];
            $this->savePhone($id, $phone, $name);
        }

        if (!empty($this->input['message']['text'])) {
            if ($this->input['message']['text'] === 'quiz') {
                $this->quizMenu('main');
            }
            $text = $this->input['message']['text'];
            if (strpos($text, '+79') !== false || strpos($text, '89') !== false) {
                $this->telegram->sendMessage([
                    'parse_mode' => 'html',
                    'chat_id' => $this->input['message']['chat']['id'],
                    'text' => 'Пожалуйста, нажмите кнопку "Поделиться номером" под полем для ввода текста. Текст вводить не нужно!😉',
                ]);
            }
//            if (strpos(',', $text) == false) {
//                $this->setNewUserAndValidation($id, $text);
//                $this->telegram->sendMessage([
//                    'parse_mode' => 'html',
//                    'chat_id' => $this->input['message']['chat']['id'],
//                    'text' => $text,
//                ]);
//            } else {
//                $this->telegram->sendMessage([
//                    'parse_mode' => 'html',
//                    'chat_id' => $this->input['message']['chat']['id'],
//                    'text' => "Пожалуйста укажите данные как из примера",
//                ]);
//            }
        }
    }

    public function savePhone($id, $phone, $name)
    {
        $customer = QuizUsers::findOne(['user_id' => $id]);
        if (empty($customer->phone) || $customer->phone == NULL) {
            $phone = $this->reformatPhone($phone);
            $customer->phone = $phone;
            $customer->name = $name;
            $customer->status = '1';
            $customer->save();

            // Отправка в канал
            if (isset($customer->name)) {
                $name = $customer->name;
            }
            if (!empty($customer->location) && !empty($customer->rooms) && !empty($customer->metro) && !empty($customer->renovation) && !empty($customer->price)) {
                $body = "Имя: $customer->name,<br> Локация: $customer->location,<br>Комнатность: $customer->rooms,<br>Близость метро: $customer->metro,<br>Отделка: $customer->renovation,<br>Цена: $customer->price";
            } else {
                $body = 'Пусто';
            }
            if (isset($customer->phone)) {
                $phone = $customer->phone;
            }
            $this->sendInfoToChannel($phone, $name, $body);
        }
    }

    private function saveChatId($chat_id){
        if (!QuizUsers::find()->where(['user_id' => $chat_id])->exists()) {
            $quizUser = new QuizUsers();
            $quizUser->user_id = trim($chat_id);
            $quizUser->date = date('Y-m-d H:i:s', time());
            $quizUser->bot = 'q2';
            if (!$quizUser->save()) {
                $this->arErrs[] = $quizUser->getErrors();
            }
        }
    }

    private function setNewUserAndValidation($id, $text)
    {
        $dataInput = explode(',', $text);
        $phone = trim($dataInput[1]);
        $name = trim($dataInput[0]);
        $phoneValidation = $this->reformatPhone($phone);
        if (strlen($phoneValidation) > 11 || strlen($phoneValidation) < 11) {
            $this->telegram->sendMessage([
                'parse_mode' => 'html',
                'chat_id' => $this->input['message']['chat']['id'],
                'text' => "Неверно указан номер телефона. Попробуйте еще раз",
            ]);
        } else {
            $date = date('Y-m-d');
            $model = QuizUsers::findOne(['user_id' => $this->input['message']['chat']['id']]);
            $model->user_id = trim($this->input['message']['chat']['id']);
            $model->name = $name;
            $model->phone = $phoneValidation;
            $model->date = $date;
            $model->status = '1';
            $model->save();

            $this->telegram->sendMessage([
                'parse_mode' => 'html',
                'chat_id' => $this->input['message']['chat']['id'],
                'text' => "Вы успешно авторизовались! Можете продолжить подборку",
            ]);

            $customer = QuizUsers::find()
                ->where(['user_id' => $this->input['message']['chat']['id']])
                ->one();


            if (isset($customer->name)) {
                $name = $customer->name;
            }

            if (!empty($customer->location) && !empty($customer->rooms) && !empty($customer->metro) && !empty($customer->renovation) && !empty($customer->price)) {
                $body = "Имя: $customer->name,<br> Локация: $customer->location,<br>Комнатность: $customer->rooms,<br>Близость метро: $customer->metro,<br>Отделка: $customer->renovation,<br>Цена: $customer->price";
            } else {
                $body = 'Пусто';
            }

            $this->quizm($this->input['message']['chat']['id']);
        }
    }

    public function isCommand()
    {
        $text = '';
        if (!empty($this->input['message']['text'])) {
            $text = $this->input['message']['text'];

        }
        $chat_id = $this->input['message']['chat']['id'];
        switch ($text) {
            case '/restart':
                $this->quizMenu('main',$chat_id);
                break;
            case '/menu':
                $this->telegram->sendMessage([
                    'chat_id' => $this->input['message']['chat']['id'],
                    'text' => implode("\n", $this->menu)
                ]);
            case '/dev':
                $this->quizm($chat_id);
                break;
            case '/start':
                $this->saveChatId($chat_id);
                $this->quizMenu('main',$chat_id);
                break;
            case '/ping':
                $this->telegram->sendMessage([
                    'chat_id' => $this->input['message']['chat']['id'],
                    'text' => 'pong'
                ]);
                break;
            case '/get_id':
                $this->telegram->sendMessage([
                    'chat_id' => $this->input['message']['chat']['id'],
                    'text' => "chat_id: {$this->input['message']['chat']['id']}\nuser_id: {$this->input['message']['from']['id']}"
                ]);
                break;
            case '/phone':
                $this->getPhoneButton($chat_id);
                break;
        }
    }

    public function getPhoneButton($user_id) {
        $btn[] =  new KeyboardButton([
            'text' => '👉 Поделиться номером',
            'request_contact' => true,
        ]);
        $this->telegram->sendMessage([
            'chat_id' => $user_id,
            'text' => 'Поделитесь номером телефона для продолжения 
(нажмите на кнопку ⬇️)',
            'reply_markup' => json_encode([
                'keyboard' => [$btn],
                'one_time_keyboard' => true,
                'resize_keyboard' => true
            ])
        ]);
    }
    public function replyKeyboardRemove($user_id) {
        $this->telegram->sendMessage([
            'chat_id' => $user_id,
            'text' => '✅',
            'reply_markup' => json_encode([
                'remove_keyboard' => true
            ])
        ]);
    }

    public function isAuth()
    {
        if (empty($this->input['message']['chat']['id']) && empty($this->input['callback_query'])) {
            return false;
        }
        if ($this->loggingSpam()) {
            return false;
        }
        return true;
    }

    public function isCallback()
    {
        if (strpos($this->input['callback_query']['data'], 'isComplete') === 0) {
            $command = 'isCompleteQuiz';
            $label = '';
            if (!empty(explode('|', $this->input['callback_query']['data'])[1])) {
                $label = explode('|', $this->input['callback_query']['data'])[1];
            }
            $id = '';
            if (!empty(explode('|', $this->input['callback_query']['data'])[2])) {
                $id = explode('|', $this->input['callback_query']['data'])[2];
            }
//            $location = '';
//            if (!empty(explode('|', $this->input['callback_query']['data'])[2])) {
//                $location = explode('|', $this->input['callback_query']['data'])[2];
//            }
//            $rooms = '';
//            if (!empty(explode('|', $this->input['callback_query']['data'])[3])) {
//                $rooms = explode('|', $this->input['callback_query']['data'])[3];
//            }
//            $metro = '';
//            if (!empty(explode('|', $this->input['callback_query']['data'])[4])) {
//                $metro = explode('|', $this->input['callback_query']['data'])[4];
//            }
//            $renovation = '';
//            if (!empty(explode('|', $this->input['callback_query']['data'])[5])) {
//                $renovation = explode('|', $this->input['callback_query']['data'])[5];
//            }
//            $price = '';
//            if (!empty(explode('|', $this->input['callback_query']['data'])[6])) {
//                $price = explode('|', $this->input['callback_query']['data'])[6];
//            }
//            $alias = '';
//            if (!empty(explode('|', $this->input['callback_query']['data'])[7])) {
//                $alias = explode('|', $this->input['callback_query']['data'])[7];
//            }

            $this->$command($this->input['callback_query']['message'], $label, $id);
        } elseif (strpos($this->input['callback_query']['data'], 'main') === 0) {
            $chat_id = $this->input['callback_query']['message']['chat']['id'];
            $this->quizMenu($this->input['callback_query']['data'],$chat_id, $this->input['callback_query']['message']);
        }
    }

    public function apiSendMessage($post)
    {
        if (!empty($post['chat_id']) && !empty($post['text'])) {
            foreach ($post['chat_id'] as $chat_id) {
                $this->telegram->sendMessage([
                    'parse_mode' => 'html',
                    'chat_id' => $chat_id,
                    'text' => $post['text']
                ]);
            }
        }
    }

    private function quizm($chat_id){

        $customer = QuizUsers::findOne(['user_id' => $chat_id]);
        $path = $customer->path_tg;
        $isPlus = (strpos($path, 'objectLists+') !== false);
        $dataPath = explode('|', $path);
        try{
            $location = $this->reformatLocation($customer->location);
            $rooms = $this->reformatRooms($customer->rooms);
            $metro = $this->reformatMetro($customer->metro);
            $renovation = $this->reformatRenovation($customer->renovation);
            $price = $this->reformatPrice($customer->price);
            $arr = $this->getAds($location, $rooms, $metro, $renovation, $price);
            $i = 0;

            foreach ($arr as $row) {
                // Дописать ЖК к name
                if (strpos($row['name'], 'ЖК ') === false) {
                    $row['name'] = 'ЖК «' . $row['name'] . '»';
                }
                //1-label, 2-location, 3-rooms, 4 - metro, 5 - renovation, 6 - price, 7 - photo
                $this->mainMenu['main']['menu']['objectLists']['menu'][$dataPath[2]]['menu']
                [$dataPath[3]]['menu'][$dataPath[4]]['menu'][$dataPath[5]]['menu'][$dataPath[6]]['menu'][$i] = [
                    'text' => $row['name'],
                    'func' => 'isComplete|' . $i . '|' . $row['id'],
                ];
                $i++;
            }
        }catch (\yii\db\Exception $e){

        }

        $messageMenu = 'Menu';
        $inlineKeyboard = [];
        $isEmptyMenu = false;

        $menuLocal = $this->mainMenu;

        $Paths = [];
        try {
            foreach (explode('|', $path) as $lot) {
                if (!empty($lot)) {
                    $Paths[] = $lot;
                    if (!empty($menuLocal[$lot]['message'])) {
                        $messageMenu = '
По вашему запросу найдены следующие новостройки; 
Для повторного прохождения запустите 
/start';
                    }
                    $isEmptyMenu = true;
                    if (!empty($menuLocal[$lot]['menu'])) {
                        $menuLocal = $menuLocal[$lot]['menu'];
                        $isEmptyMenu = false;
                    }
                }
            }
        } catch (Exception $e) {
            if (empty($Paths)) {
                $this->telegram->sendMessage([
                    'chat_id' => 451426410,
                    'text' => 'error'
                ]);
            }
            die;
        }

        if (empty($Paths)) {
            $this->telegram->sendMessage([
                'chat_id' => $this->input['message']['chat']['id'],
                'text' => 'error menu'
            ]);
        }
        foreach ($menuLocal as $callback => $menu) {
            $callbackData = implode('|', $Paths) . '|' . $callback;
            if (!empty($menu['func'])) {
                $callbackData = $menu['func'];
            }
            $inlineKeyboard[] = ['text' => $menu['text'], 'callback_data' => $callbackData];
        }
        $inlineKeyboard = array_slice($inlineKeyboard, '0', '6');

        if (empty($callbackMessage)) {
            $this->telegram->sendMessage([
                'chat_id' => $this->input['message']['chat']['id'],
                'text' => $messageMenu,
                'reply_markup' => json_encode([
                    'inline_keyboard' => array_chunk($inlineKeyboard, 1)
                ])
            ]);
        }
    }

    private function quizMenu($path,$chat_id, $callbackMessage = null)
    {
        $isPlus = (strpos($path, '+') !== false);
        $customer = QuizUsers::findOne(['user_id' => $chat_id]);
        $path = str_replace('+', '', $path);
        $dataPath = explode('|', $path);

        try {
            // Кнопка
            if (!empty($path) && strpos($path, 'objectLists') !== false) {
                // 1 Шаг - Локация
                $user_id = $this->input['callback_query']['message']['chat']['id'];
                $location = '';
                if (isset($dataPath[2])) {
                    $location = $this->mainMenu['main']['menu']['objectLists']['menu'][$dataPath[2]]['text'];
                }
                $rooms = '';
                if (isset($dataPath[3])) {
                    $rooms = $this->mainMenu['main']['menu']['objectLists']['menu'][$dataPath[2]]['menu']
                    [$dataPath[3]]['text'];
                }
                $metro = '';
                if (isset($dataPath[4])) {
                    $metro = $this->mainMenu['main']['menu']['objectLists']['menu'][$dataPath[2]]['menu']
                    [$dataPath[3]]['menu'][$dataPath[4]]['text'];
                }
                $renovation = '';
                if (isset($dataPath[5])) {
                    $renovation = $this->mainMenu['main']['menu']['objectLists']['menu'][$dataPath[2]]['menu']
                    [$dataPath[3]]['menu'][$dataPath[4]]['menu'][$dataPath[5]]['text'];
                }
                // 5 шаг - цена
                $price = '';
                if (isset($dataPath[6])) {
                    // Статус
                    $customer = QuizUsers::findOne(['user_id' => $this->input['callback_query']['message']['chat']['id']]);
                    if (isset($customer->status) || (!empty($customer->status))) {
                        $status = $customer->status;
                    } else {
                        $status = 0;
                    }

                    $price = $this->mainMenu['main']['menu']['objectLists']['menu'][$dataPath[2]]['menu']
                    [$dataPath[3]]['menu'][$dataPath[4]]['menu'][$dataPath[5]]['menu'][$dataPath[6]]['text'];

                    if (!empty($location) && !empty($rooms) && !empty($metro) && !empty($renovation) && !empty($price)) {
                        $this->saveDataSelectedUserQuiz($user_id,$location,$rooms,$metro,$renovation,$price,$path);
                    }

                    // Проверка на статус, если 0 то просим заполнить форму
                    if ($status == 0) {
                        $this->getPhoneButton($user_id);
                    } else {
                        if (!empty($location) && !empty($rooms) && !empty($metro) && !empty($renovation) && !empty($price)) {
                            $location = $this->reformatLocation($location);
                            $rooms = $this->reformatRooms($rooms);
                            $metro = $this->reformatMetro($metro);
                            $renovation = $this->reformatRenovation($renovation);
                            $price = $this->reformatPrice($price);
                            $arr = $this->getAds($location, $rooms, $metro, $renovation, $price);
                            $i = 0;

                            foreach ($arr as $row) {
                                if ($row['renovation'] !== 'нет') {
                                    $renovation = 'есть';
                                } else {
                                    $renovation = 'нет';
                                }

                                // Дописать ЖК к name
                                if (strpos($row['name'], 'ЖК ') === false) {
                                    $row['name'] = 'ЖК «' . $row['name'] . '»';
                                }
                                //1-label, 2-location, 3-rooms, 4 - metro, 5 - renovation, 6 - price, 7 - photo
                                $this->mainMenu['main']['menu']['objectLists']['menu'][$dataPath[2]]['menu']
                                [$dataPath[3]]['menu'][$dataPath[4]]['menu'][$dataPath[5]]['menu'][$dataPath[6]]['menu'][$i] = [
                                    'text' => $row['name'],
                                    'func' => 'isComplete|' . $i . '|' . $row['novos_id'],
                                ];
                                $i++;
                            }
                        }
                    }
                }
            }
        } catch (\yii\db\Exception $e) {

        }
        $messageMenu = 'Menu';
        $inlineKeyboard = [];
        $isEmptyMenu = false;

        $menuLocal = $this->mainMenu;
        $Paths = [];

        try {
            foreach (explode('|', $path) as $lot) {
                if (!empty($lot)) {
                    $Paths[] = $lot;
                    if (!empty($menuLocal[$lot]['message'])) {
                        $messageMenu = $menuLocal[$lot]['message'];
                    }
                    $isEmptyMenu = true;
                    if (!empty($menuLocal[$lot]['menu'])) {
                        $menuLocal = $menuLocal[$lot]['menu'];
                        $isEmptyMenu = false;
                    }
                }
            }
        } catch (Exception $e) {
            if (empty($Paths)) {
                $this->telegram->sendMessage([
                    'chat_id' => 451426410,
                    'text' => 'error'
                ]);
            }
            die;
        }

        $prevButton = array_slice($Paths, 0, -1);
        if (empty($Paths)) {
            $this->telegram->sendMessage([
                'chat_id' => $this->input['message']['chat']['id'],
                'text' => 'error menu'
            ]);
        }
        foreach ($menuLocal as $callback => $menu) {
            $callbackData = implode('|', $Paths) . '|' . $callback;
            if (!empty($menu['func'])) {
                $callbackData = $menu['func'];
            }
            $inlineKeyboard[] = ['text' => $menu['text'], 'callback_data' => $callbackData];
        }

        if (empty($callbackMessage)) {
            $this->telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => $messageMenu,
                'parse_mode' => 'html',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [$inlineKeyboard]
                ])
            ]);
        } else {
//             Если есть меню на 1 уровень выше - добавляем кнопку Назад
            if (!empty($prevButton) && (strpos($path, 'price_') === false)) {
                $inlineKeyboard[] = ['text' => "« назад", 'callback_data' => implode('|', $prevButton)];
            }
            if ($isEmptyMenu) {
                if ($customer->status === NULL) {
                    $this->telegram->deleteMessage([
                        'chat_id' => $callbackMessage['chat']['id'],
                        'message_id' => $callbackMessage['message_id'],
                    ]);
                } else {
                    $inlineKeyboard = [['text' => '« назад', 'callback_data' => implode('|', $prevButton)]];
                    $messageMenu = 'По заданным параметрам ничего не найдено, попробуйте другие';
                }
//                $inlineKeyboard = [['text' => '« назад', 'callback_data' => implode('|', $prevButton)]];
            }
            if (count($inlineKeyboard) == 5){
                $replyMarkup = [
                    'inline_keyboard' => array_chunk($inlineKeyboard, 2)
                ];
            } elseif (count($inlineKeyboard) == 3){
                $replyMarkup = [
                    'inline_keyboard' => array_chunk($inlineKeyboard, 2)
                ];
            }  else {
                $replyMarkup = [
                    'inline_keyboard' => array_chunk($inlineKeyboard, 3)
                ];
            }


            if (strpos($path, 'price_') !== false && $isEmptyMenu === false) {
                $replyMarkup = [
                    'inline_keyboard' => array_chunk($inlineKeyboard, 1)
                ];
                $pathPrice = '';
                $pathPrice = explode('|', $path);
                $pathPrice = end($pathPrice);

                $dd = array_chunk($replyMarkup['inline_keyboard'], (count($replyMarkup['inline_keyboard']) / 3) + 1);
                $str = 6;
                if ($isPlus) {
                    $localKeyboard = $dd[1];
                    $localKeyboard[] = [
                        ['text' => "« назад", 'callback_data' => implode('|', $prevButton) . "|$pathPrice"],
//                        ['text' => "ещё жк  »", 'callback_data' => implode('|', $prevButton) . "|$pathPrice"],
                    ];
                } else {
                    if (count($inlineKeyboard) < 7) {
                        $localKeyboard = $replyMarkup['inline_keyboard'];
                        $localKeyboard[] = [
                            ['text' => "« назад", 'callback_data' => implode('|', $prevButton)],
//                            ['text' => "ещё жк  »", 'callback_data' => implode('|', $prevButton) . "|$pathPrice+"],
                        ];
                    } else {
                        $localKeyboard = $dd[0];
                        $localKeyboard[] = [
                            ['text' => "« назад", 'callback_data' => implode('|', $prevButton)],
                            ['text' => "ещё жк  »", 'callback_data' => implode('|', $prevButton) . "|$pathPrice+"],
                        ];
                    }
                }

                $this->telegram->editMessageText([
                    'chat_id' => $callbackMessage['chat']['id'],
                    'message_id' => $callbackMessage['message_id'],
                    'parse_mode' => 'html',
                    'text' => $messageMenu,
                    'reply_markup' => json_encode(['inline_keyboard' => $localKeyboard])
                ]);
            } else {
                $this->telegram->editMessageText([
                    'chat_id' => $callbackMessage['chat']['id'],
                    'message_id' => $callbackMessage['message_id'],
                    'parse_mode' => 'html',
                    'text' => $messageMenu,
                    'reply_markup' => json_encode($replyMarkup)
                ]);
            }
        }
    }

    private function saveDataSelectedUserQuiz($user_id,$location, $rooms, $metro, $renovation, $price, $path)
    {
        if (!QuizUsers::find()->where(['user_id' => $user_id])->exists()) {
            $quizUser = new QuizUsers();
            $quizUser->user_id = trim($user_id);
            $quizUser->location = $location;
            $quizUser->rooms = $rooms;
            $quizUser->metro = $metro;
            $quizUser->renovation = $renovation;
            $quizUser->price = $price;
            $quizUser->path_tg = $path;
            if (!$quizUser->save()) {
                $this->arErrs[] = $quizUser->getErrors();
            }
        } else {
            $oldUser = QuizUsers::findOne(['user_id' => $user_id]);
            $oldUser->location = $location;
            $oldUser->rooms = $rooms;
            $oldUser->metro = $metro;
            $oldUser->renovation = $renovation;
            $oldUser->price = $price;
            $oldUser->path_tg = $path;
            if (!$oldUser->save()) {
                $this->arErrs[] = $oldUser->getErrors();
            }
        }
    }

    private function getAds($location, $rooms, $metro, $renovation, $price)
    {
        return Yii::$app->novostroyDB->createCommand("select ads.id,ads.district,ads.rooms,ads.renovation,ads.price,ads.photo,
        novos.name, novos.alias, pm.on_foot, pm.on_transport, metro.name as metro, ads.novos_id
                    from ads
                        inner join novos on ads.novos_id = novos.id
                               inner join property_metro pm on novos.id = pm.source_id
                                    inner join property_type pt ON pt.source = 'novos' AND pt.code = 'metro'
                                        inner join metro on ads.metro_id = metro.id
                    WHERE ads.state = 2
                      and pm.prop_type_id = pt.id
                      and ads.district = '$location'
                      and ads.rooms $rooms
                      and ads.renovation $renovation
                      and ads.price $price
                      $metro
                      GROUP BY ads.novos_id
                      ORDER BY if (novos.super_advertiser or novos.advertiser, 0, 1), price
                      limit 15;
                    ")->queryAll();
    }

    private function getLot($id)
    {
        return Yii::$app->novostroyDB->createCommand("select ads.id,ads.district,ads.rooms,ads.renovation,ads.price,ads.photo,
        novos.name, novos.alias, pm.on_foot, pm.on_transport, metro.name as metro, files.file
                    from ads
                        inner join novos on ads.novos_id = novos.id
                               inner join property_metro pm on novos.id = pm.source_id
                                    inner join property_type pt ON pt.source = 'novos' AND pt.code = 'metro'
                                        inner join metro on ads.metro_id = metro.id
                                            inner join files on novos.gallery_id = files.gallery_id 
                    where ads.state = 2
                      and ads.novos_id = $id
                    ORDER BY price
                    limit 1;
                    ")->queryAll();
    }

    private function isCompleteQuiz($callbackMessage, $label = '', $id = '')
    {
        foreach ($this->input['callback_query']['message']['reply_markup']['inline_keyboard'] as $row) {
            foreach ($row as $td) {
                if ($td['callback_data'] === $this->input['callback_query']['data']) {
                    $label = $td['text'];
                    break;
                }
            }
        }
        $lot = $this->getLot($id)[0];
        $location = $lot['district'];
        $rooms = $lot['rooms'];
        $metro = '';

        if (!empty($lot['on_foot'])) {
            $metro = $lot['on_foot']. ' минут пешком до метро';
        } elseif (!empty($lot['on_transport'])) {
            $metro = $lot['on_transport']. ' минут на транспорте до метро';
        }

        $renovation = $lot['renovation'];
        if (empty($renovation)) {
            $renovation = 'Без отделки';
        } elseif ($renovation == 'дизайнерский') {
            $renovation = 'Дизайнерская';
        } elseif ($renovation == 'white box') {
            $renovation = 'Предчистовая';
        } elseif ($renovation == 'нет') {
            $renovation = 'Без отделки';
        } elseif ($renovation == 'с отделкой') {
            $renovation = 'С отделкой';
        }

        $price = $lot['price'];
        $alias = $lot['alias'];
        $link = "https://www.novostroy-m.ru/baza/$alias";
//        $photo = 'https://admin2.novostroy-m.ru/images/presets/msk/plans/1024x768/'.$rooms;
//        $photo = 'https://wallpaperstrend.com/wp-content/uploads/Animals-and-Birds/Animals01/lemur-sit-and-watch-3840x2160.jpg';
        switch ($location) {
            case 'msk':
                $location = 'Москва';
                break;
            case 'mo':
                $location = 'Московская область';
                break;
            case 'newmsk':
                $location = 'Новая москва';
                break;
        }
        switch ($rooms) {
            case 's':
                $rooms = 'Студия';
                break;
            case '1':
                $rooms = '1-комн. квартира';
                break;
            case '2':
                $rooms = '2-комн. квартира';
                break;
            case '3':
                $rooms = '3-комн. квартира';
                break;
        }
        $utmLink = "$link?utm_source=tg_bot";
        $price = number_format($price, 0, '.', ' ');
        //1-label, 2-location, 3-rooms, 4 - metro, 5 - renovation, 6 - price, 7 - photo
        $message = '';
        $message .= "<b>📍 $location. $label</b> \n";
        $message .= "<a href='$utmLink'>
Интерактивный просмотр
конкретных квартир в корпусах ЖК</a> ⬅️ \n
";
        $message .= "- $rooms от $price \n";
        $message .= "- $metro  \n";
        $message .= "- $renovation \n";
        $message .= "
+74951475435\n";
//        $message .= "\nНужно больше информации? Нажмите /form заполните форму и мы обязательно свяжемся с вами!";

        $this->telegram->sendMessage([
            'chat_id' => $callbackMessage['chat']['id'],
//            'message_id' => $callbackMessage['message_id'],
            'text' => $callbackMessage['text'],
            'parse_mode' => 'html',
            'reply_markup' => json_encode($callbackMessage['reply_markup'])
        ]);

        $this->telegram->editMessageText([
            'chat_id' => $callbackMessage['chat']['id'],
            'message_id' => $callbackMessage['message_id'],
            'text' => $message,
            'parse_mode' => 'html',
            'disable_web_page_preview' => True
        ]);
    }

    public function sendInfoToChannel($phone, $name, $body)
    {
        $msg_body = [
            'phone' => $phone,
            'name' => $name,
            'list_id' => 321,
            'params' => [
                'region' => 'msk',
                'url' => 'http://localhost?utm_campaign=tg_bot'
            ],
            'email' => [
                'title' => "Квиз telegrambot $phone",
                'sender' => [
                    'email' => 'contact@m-novostroy.ru',
                    'name' => 'telegrambot',
                ],
                'emails' => [
                    0 => 'd.melnikov@mediapronet.ru',
                    1 => 'admin@mediapronet.ru',
                    2 => 'p.paramonenkov@mediapronet.ru',
                ],
                'body' => $body,
            ],
            'title' => 'Квиз [telegrambot] ',
        ];

        $connection = new AMQPStreamConnection('195.54.207.74', 5672, 'telegram', 'w4rGsNSYsSwY');
        $exchange = 'quizes'; // Обменник
        $queue = 'quizes'; // Очередь

        $channel = $connection->channel();
        $channel->queue_declare($queue, false, true, false, false);
        $channel->exchange_declare($exchange, AMQPExchangeType::TOPIC, false, true, false);
        $channel->queue_bind($queue, $exchange);

        $message = new AMQPMessage(json_encode($msg_body, JSON_UNESCAPED_UNICODE));
        $channel->basic_publish($message, $exchange);
        $channel->close();
        $connection->close();
    }

    public function reformatLocation($location)
    {
        switch ($location) {
            case 'В Москве':
                $location = 'msk';
                break;
            case 'В Подмосковье':
                $location = 'mo';
                break;
            case 'в Новой Москве':
                $location = 'newmsk';
                break;
        }
        return $location;
    }

    public function reformatRooms($rooms)
    {
        switch ($rooms) {
            case 'Студия':
                $rooms = "= 's'";
                break;
            case '1-комнатная':
                $rooms = '= 1';
                break;
            case '2-комнатная':
                $rooms = '= 2';
                break;
            case '3 и более комнат':
                $rooms = '>= 3';
                break;
        }
        return $rooms;
    }

    public function reformatMetro($metro)
    {
        switch ($metro) {
            case 'Важно':
                $metro = 'and pm.on_foot <= 30';
                break;
            case 'Не важно':
                $metro = '';
                break;
            case 'Не пользуюсь метро':
                $metro = 'and pm.on_foot >= 1';
                break;
        }
        return $metro;
    }

    public function reformatRenovation($renovation)
    {
        switch ($renovation) {
            case 'Без отделки':
                $renovation = "= 'нет'";
                break;
            case 'С отделкой':
                $renovation = "!= 'нет'";
                break;
        }

        return $renovation;
    }

    public function reformatPrice($price)
    {
        switch ($price) {
            case 'До 10 млн руб.':
                $price = '<= 10000000';
                break;
            case 'До 15 млн руб.':
                $price = '<= 15000000';
                break;
            case 'Более 15 млн руб.':
                $price = '>= 15000000';
                break;
            case 'До 12 млн руб.':
                $price = '<= 12000000';
                break;
        }
        return $price;
    }

    public function webhook()
    {
        $this->init('');
        $this->telegram->setWebhook(['url' => $this->webhookUrl]);
    }

    public function isValidEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // Валидация номера телефона
    public function reformatPhone($phone)
    {
        $phone = trim($phone);

        $res = preg_replace(
            array(
                '/[\+]?([7|8])[-|\s]?\([-|\s]?(\d{3})[-|\s]?\)[-|\s]?(\d{3})[-|\s]?(\d{2})[-|\s]?(\d{2})/',
                '/[\+]?([7|8])[-|\s]?(\d{3})[-|\s]?(\d{3})[-|\s]?(\d{2})[-|\s]?(\d{2})/',
                '/[\+]?([7|8])[-|\s]?\([-|\s]?(\d{4})[-|\s]?\)[-|\s]?(\d{2})[-|\s]?(\d{2})[-|\s]?(\d{2})/',
                '/[\+]?([7|8])[-|\s]?(\d{4})[-|\s]?(\d{2})[-|\s]?(\d{2})[-|\s]?(\d{2})/',
                '/[\+]?([7|8])[-|\s]?\([-|\s]?(\d{4})[-|\s]?\)[-|\s]?(\d{3})[-|\s]?(\d{3})/',
                '/[\+]?([7|8])[-|\s]?(\d{4})[-|\s]?(\d{3})[-|\s]?(\d{3})/',
            ),
            array(
                '7$2$3$4$5',
                '7$2$3$4$5',
                '7$2$3$4$5',
                '7$2$3$4$5',
                '7$2$3$4',
                '7$2$3$4',
            ),
            $phone
        );
        $res[0] = 8;
        return $res;
    }

//1. Где присматриваете квартиру? (В Москве, в Московской области, в Новой Москве)
//
//2. Сколько комнат должно быть в квартире? (студия, 1ккв, 2ккв, от 3 и более)
//
//3. Насколько важна близость метро? (Важно, готов ездить на автобусе, не пользуюсь метро)
//
//4. Хотите заехать сразу или готовы подождать? (хочу готовое, готов ждать не более года, не имеет значения)
//
//5. Отделка &#127963; (с отделкой, без отделки)
//
//6. На какой бюджет вы рассчитываете? &#128176; (До 10 млн руб., До 15 млн руб., Более 15 млн руб. руб)
    private $mainMenu = [
        'main' => [
            'message' => 'В базе бота больше 2500 новостроек с актуальными ценами от 2,6 млн рублей и скидками до 35%.
Ответь всего на несколько вопросов, чтобы подобрать для себя идеальную квартиру 😊',
            'menu' => [
                'objectLists' => [
                    'text' => 'Начать подбор 🌟',
                    'message' => 'Где присматриваете квартиру? &#127970;',
                    'menu' => [
                        'moscow' => [
                            'text' => 'В Москве',
                            'message' => 'Сколько комнат должно быть в квартире? 🏠',
                            'menu' => [
                                'rooms_s' => [
                                    'text' => 'Студия',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'rooms_1' => [
                                    'text' => '1-комнатная',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'rooms_2' => [
                                    'text' => '2-комнатная',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'rooms_3' => [
                                    'text' => '3 и более комнат',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'mo' => [
                            'text' => 'В Подмосковье',
                            'message' => 'Сколько комнат должно быть в квартире? 🏠',
                            'menu' => [
                                'rooms_s' => [
                                    'text' => 'Студия',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'rooms_1' => [
                                    'text' => '1-комнатная',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'rooms_2' => [
                                    'text' => '2-комнатная',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'rooms_3' => [
                                    'text' => '3 и более комнат',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'newmsk' => [
                            'text' => 'в Новой Москве',
                            'message' => 'Вернуться назад',
                            'menu' => [
                                'rooms_s' => [
                                    'text' => 'Студия',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'rooms_1' => [
                                    'text' => '1-комнатная',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'rooms_2' => [
                                    'text' => '2-комнатная',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 10 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'rooms_3' => [
                                    'text' => '3 и более комнат',
                                    'message' => 'Насколько важна близость метро? 🚊',
                                    'menu' => [
                                        'metro_1' => [
                                            'text' => 'Важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'metro_2' => [
                                            'text' => 'Не важно',
                                            'message' => 'Отделка 🛠',
                                            'menu' => [
                                                'renovation_yes' => [
                                                    'text' => 'С отделкой',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                                'renovation_no' => [
                                                    'text' => 'Без отделки',
                                                    'message' => 'На какой бюджет вы рассчитываете? &#128176;',
                                                    'menu' => [
                                                        'price_1' => [
                                                            'text' => 'До 12 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_2' => [
                                                            'text' => 'До 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                        'price_3' => [
                                                            'text' => 'Более 15 млн руб.',
                                                            'message' => 'По вашему запросу найдены следующие новостройки ⬇️',
                                                            'menu' => [

                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        ],
    ];
}