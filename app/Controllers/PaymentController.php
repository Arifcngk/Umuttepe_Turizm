<?php

namespace App\Controllers;

use App\Libraries\Iyzico;
use App\Models\RouteModel;
use App\Models\FareModel;
use App\Controllers\AuthController;
use App\Models\SeatModel;
use App\Models\TicketModel;
use App\Models\PassengerModel;
use App\Models\UserModel;

class PaymentController extends BaseController
{
    public function index()
    {
        return view("pages/ticket_payment");
    }
    public function payment()
    {
        // Kullanıcının oturumunu kontrol et
        if (!AuthController::check()) {
            $message = ['type' => 'error', 'text' => 'Lütfen giriş yapınız !'];
            return redirect()->to('/')->with('message', $message);
        }

        $phoneNumber = $this->request->getPost('phone_number');
        $email = $this->request->getPost('email');

        // Yolcu bilgilerini al
        $passengerInfo = [];
        foreach ($this->request->getPost('passenger_name') as $index => $passengerName) {
            $passengerInfo[$index]['name'] = $passengerName;
            $passengerInfo[$index]['status'] = isset($this->request->getPost('status')[$index]) ? 'reserved' : 'sold';
            $passengerInfo[$index]['seat_number'] = $this->request->getPost('seat_number')[$index];
            $passengerInfo[$index]['surname'] = $this->request->getPost('passenger_surname')[$index];
            $passengerInfo[$index]['gender'] = $this->request->getPost('passenger_gender')[$index];
            $passengerInfo[$index]['birthdate'] = $this->request->getPost('passenger_birthdate')[$index];
            $passengerInfo[$index]['id_number'] = $this->request->getPost('passenger_id_number')[$index];
            $passengerInfo[$index]['discount'] = $this->request->getPost('passenger_discount')[$index];
        }

        // Rota ID'sini al
        $routeId = $this->request->getPost('route_id');

        // 1. Kontrol Etme
        if (!is_numeric($routeId)) {
            // Hatalı $routeId, uygun bir işlem yapın
            exit('Hatalı rota kimliği');
        }

        $routeModel = new RouteModel();
        // 2. Veri Varlığını Kontrol Etme
        $route = $routeModel->find($routeId);
        if (!$route) {
            // Belirtilen rota bulunamadı, uygun bir işlem yapın
            exit('Belirtilen rota bulunamadı');
        }

        // 3. Dizinden Veri Çekme ve Güvenli Fiyat Alma
        $price = isset($route['price']) ? $route['price'] : null;
        if (!$price) {
            // Fiyat bulunamadı veya geçersiz, uygun bir işlem yapın
            exit('Fiyat bilgisi bulunamadı');
        }

        // Rota fiyatını güncellemek için her bir yolcu için geçerli indirimi ve oranı hesaplayın
        $totalPrice = 0;
        $ıtems = [];
        foreach ($passengerInfo as $passenger) {
            $discountId = $passenger['discount'];

            // Geçerli indirim oranını Fare modelinden alın
            $fareModel = new FareModel();
            $fare = $fareModel->find($discountId);

            // Geçerli indirim oranını kontrol edin
            if (!$fare) {
                // Belirtilen indirim oranı bulunamadı, uygun bir işlem yapın
                exit('Tarife bulunamadı');
            }

            // Yolcu için geçerli indirim oranını hesaplayın
            $rate = $fare['rate'];

            $ıtems += [
                'id' => 868,
                'name' => $routeId . " numaralı seferde " . $passenger['seat_number'] . " numaralı koltuk",
                'category' => $fare['fare_name'],
                'price' => $price * $fare['rate']
            ];

            // Yolculuk fiyatını, her bir yolcu için alınan indirim oranıyla çarpın
            $totalPrice += $passenger['status'] == 'sold' ? $price * $rate : 0;
        }

        $paymentInfo = [
            'phone_number' => $phoneNumber,
            'email' => $email,
            'passenger_info' => $passengerInfo,
            'route_id' => $routeId,
            // Diğer bilgileri ekleyin
        ];

        session()->set('paymentInfo', $paymentInfo);
        $iyzico = new Iyzico();
        $user = session()->get('user');
        if ($user['balance'] < $totalPrice) {
            $netPrice = $totalPrice - $user['balance'];
            $payment = $iyzico->setForm([
                'conversationID' => '123456789',
                'price' => 180.0,
                'paidPrice' => $netPrice,
                'basketID' => 'SPT123456',
            ])
                ->setBuyer([
                    'id' => $user['id'],
                    'name' => $user['first_name'],
                    'surname' => $user['last_name'],
                    'phone' => $user['phone_number'],
                    'email' => $user['email'],
                    'identity' => $user['tc_id'],
                    'address' => isset($user['address']) ? $user['address'] : 'Adres Bulunamadı',
                    'ip' => $this->request->getIPAddress(),
                    'city' => 'Şehir Bulunamadı',
                    'country' => 'Türkiye',
                ])
                ->setShipping([
                    'name' => $user['first_name'] . ' ' .  $user['last_name'],
                    'city' => 'Şehir Bulunamadı',
                    'country' => 'Türkiye',
                    'address' => isset($user['address']) ? $user['address'] : 'Adres Bulunamadı',
                ])
                ->setBilling([
                    'name' => $user['first_name'] . ' ' . $user['last_name'],
                    'city' => 'Şehir Bulunamadı',
                    'country' => 'Türkiye',
                    'address' => isset($user['address']) ? $user['address'] : 'Adres Bulunamadı',
                ])
                ->setItems([
                    [
                        'id' => 8749,
                        'name' => 'Kırmızı Ayakkabı',
                        'category' => 'Erkek Ayakkabı',
                        'price' => 60.0,
                    ],
                    [
                        'id' => 8750,
                        'name' => 'Siyah Ayakkabı',
                        'category' => 'Erkek Ayakkabı',
                        'price' => 60.0,
                    ],
                    [
                        'id' => 8751,
                        'name' => 'Mavi Ayakkabı',
                        'category' => 'Erkek Ayakkabı',
                        'price' => 60.0,
                    ]
                ])
                ->paymentForm();
            return view('pages/ticket_payment', [
                'paymentContent' => $payment->getCheckoutFormContent(),
                'paymentStatus' => $payment->getStatus()
            ]);
        }
        else {
            $routeId = $paymentInfo['route_id'];
            $ticketModel = new TicketModel();
            $seatModel = new SeatModel();
            $user['balance'] -= $totalPrice;
            // SeatModel kullanarak koltuk bilgilerini kaydet
            foreach ($paymentInfo['passenger_info'] as $passenger) {

                $passengerModel = new PassengerModel();

                $passengerData = [
                    'first_name' => $passenger['name'],
                    'last_name' => $passenger['surname'],
                    'tc_id' => $passenger['id_number'],
                    'fare_id' => $passenger['discount']
                ];

                $passengerModel->insert($passengerData);
                $passengerId = $passengerModel->getInsertID();
                // Koltuk bilgisini al
                $seatNumber = $passenger['seat_number'];

                // İlgili koltuğu bul
                $seatId = $seatModel->where('route_id', $routeId)
                    ->where('seat_number', $seatNumber)
                    ->first()['id'];

                $seatData = [
                    'status' => $passenger['status'],
                    'gender' => $passenger['gender']
                ];

                $seatModel->update($seatId, $seatData);

                $ticketData = [
                    'route_id' => $routeId,
                    'user_id' => $user['id'], // Örneğin, kullanıcı kimliğini alarak kaydedebilirsiniz
                    'seat_number' => $passenger['seat_number'], // Birden fazla koltuk numarası olabilir, bunları virgülle ayırarak kaydedebilirsiniz
                    'created_at' => date('Y-m-d H:i:s'), // Şu anki tarih ve saat
                    'passenger_id' => $passengerId,
                    'status' => $passenger['status']
                    // Diğer alanları ekleyebilirsiniz
                ];
                $ticketModel->insert($ticketData);
            }

            session()->remove('payment_info');
            $userModel = new UserModel();
            session()->set('user', $user);
            $userModel->update($user['id'], $user);
            return redirect()->to('/')->with('message', ['type' => 'success', 'text' => 'Bilet satın alma işlemleri başarılı, tüm tutar bakiyenizden karşılandı !']);
        }
    }

    public function callback()
    {
        $token = $_REQUEST['token'];
        $conversionID = '123456789';
        $iyzico = new Iyzico();
        $response = $iyzico->callbackForm($token, $conversionID);

        $paymentStatus = $response->getPaymentStatus();

        if ($paymentStatus === 'SUCCESS') {
            $paymentInfo = session()->get('paymentInfo');
            $user = session()->get('user');
            $userModel = new UserModel();
            $user['balance'] = 0;
            $userModel->update($user['id'], $user);
            session()->set('user', $user);
            $routeId = $paymentInfo['route_id'];
            $ticketModel = new TicketModel();
            $seatModel = new SeatModel();

            // SeatModel kullanarak koltuk bilgilerini kaydet
            foreach ($paymentInfo['passenger_info'] as $passenger) {

                $passengerModel = new PassengerModel();

                $passengerData = [
                    'first_name' => $passenger['name'],
                    'last_name' => $passenger['surname'],
                    'tc_id' => $passenger['id_number'],
                    'fare_id' => $passenger['discount']
                ];

                $passengerModel->insert($passengerData);
                $passengerId = $passengerModel->getInsertID();
                // Koltuk bilgisini al
                $seatNumber = $passenger['seat_number'];

                // İlgili koltuğu bul
                $seatId = $seatModel->where('route_id', $routeId)
                    ->where('seat_number', $seatNumber)
                    ->first()['id'];

                $seatData = [
                    'status' => 'sold',
                    'gender' => $passenger['gender']
                ];

                $seatModel->update($seatId, $seatData);

                $ticketData = [
                    'route_id' => $routeId,
                    'user_id' => $user['id'], // Örneğin, kullanıcı kimliğini alarak kaydedebilirsiniz
                    'seat_number' => $passenger['seat_number'], // Birden fazla koltuk numarası olabilir, bunları virgülle ayırarak kaydedebilirsiniz
                    'created_at' => date('Y-m-d H:i:s'), // Şu anki tarih ve saat
                    'passenger_id' => $passengerId
                    // Diğer alanları ekleyebilirsiniz
                ];
                $ticketModel->insert($ticketData);
            }
            session()->remove('payment_info');
            return redirect()->to('/')->with('message', ['type' => 'success', 'text' => 'Bilet satın alma işlemleri başarılı']);
        } else {
            // Ödeme başarısız veya hata oluştu
            // Uygun bir şekilde yönlendirme veya hata mesajı gösterme işlemlerini yapabilirsiniz
            return 'Ödeme başarısız: ' . $paymentStatus;
        }
    }
}
