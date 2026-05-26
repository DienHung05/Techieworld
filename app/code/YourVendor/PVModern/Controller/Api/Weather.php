<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Api;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use YourVendor\PVModern\Model\IntegrationConfig;

class Weather implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly IntegrationConfig $integrationConfig
    ) {
    }

    public function execute()
    {
        $city = trim((string) $this->request->getParam('city', 'Hanoi')) ?: 'Hanoi';
        $lat = trim((string) $this->request->getParam('lat', ''));
        $lon = trim((string) $this->request->getParam('lon', ''));
        $unit = strtolower(trim((string) $this->request->getParam('unit', 'metric'))) === 'imperial' ? 'imperial' : 'metric';
        $live = $this->fetchOpenWeather($city, $lat, $lon, $unit);
        if ($live) {
            $result = $this->resultJsonFactory->create();
            $result->setHeader('Cache-Control', 'public, max-age=900', true);
            return $result->setData($live + ['success' => true, 'mock' => false]);
        }

        $now = gmdate('d/m/Y H:i');
        $baseTemp = stripos($city, 'ho chi minh') !== false || stripos($city, 'hồ chí minh') !== false ? 32 : 27;
        if ($unit === 'imperial') {
            $baseTemp = (int) round(($baseTemp * 9 / 5) + 32);
        }

        $result = $this->resultJsonFactory->create();
        $result->setHeader('Cache-Control', 'public, max-age=900', true);
        return $result->setData([
            'success' => true,
            'location' => $lat && $lon ? sprintf('Lat %s, Lon %s', $lat, $lon) : $city,
            'updated_at' => $now,
            'unit' => $unit,
            'provider' => 'OpenWeatherMap',
            'source_url' => 'https://openweathermap.org/',
            'note' => $this->openWeatherKey() === ''
                ? 'OPENWEATHER_API_KEY chưa được cấu hình; dữ liệu thời tiết bên dưới là dự phòng để trang không bị trống.'
                : 'OpenWeatherMap không trả dữ liệu hợp lệ trong lần gọi này; dữ liệu bên dưới là dự phòng.',
            'current' => [
                'temperature' => $baseTemp,
                'condition' => $unit === 'metric' && $baseTemp >= 30 ? 'Nắng nóng nhẹ' : 'Có mây',
                'feels_like' => $baseTemp + 2,
                'humidity' => $baseTemp >= 30 ? 68 : 76,
                'wind' => $unit === 'imperial' ? '7 mph' : ($baseTemp >= 30 ? '12 km/h' : '9 km/h'),
                'pressure' => '1012 hPa',
                'visibility' => $unit === 'imperial' ? '6 mi' : '10 km',
                'uv' => $baseTemp >= 30 ? '7 cao' : '4 vừa',
                'high' => $baseTemp + 3,
                'low' => $baseTemp - 4,
                'wind_direction' => 'NE',
                'rain_chance' => $baseTemp >= 30 ? 18 : 42,
                'sunrise' => '05:42',
                'sunset' => '18:21',
                'icon' => $baseTemp >= 30 ? '☀' : '☁',
            ],
            'aqi' => [
                'value' => $baseTemp >= 30 ? 58 : 42,
                'label' => $baseTemp >= 30 ? 'Moderate' : 'Good',
                'advice' => $baseTemp >= 30 ? 'Sensitive groups should reduce prolonged outdoor activity.' : 'Air quality is acceptable for outdoor activities.',
            ],
            'map' => [
                'lat' => $lat ?: '21.0278',
                'lon' => $lon ?: '105.8342',
                'layers' => ['rain', 'clouds', 'temperature', 'wind', 'pressure', 'radar'],
            ],
            'alert' => [
                'severity' => 'normal',
                'title' => 'Không có cảnh báo thời tiết nghiêm trọng.',
                'time' => $now,
                'description' => 'Theo dõi cập nhật mưa lớn, bão và nắng nóng trong ngày.',
            ],
            'hourly' => $this->hourly($baseTemp),
            'daily' => $this->daily($baseTemp),
            'news' => $this->weatherNews(),
            'mock' => true,
        ]);
    }

    private function fetchOpenMeteo(string $city, string $lat, string $lon, string $unit): array
    {
        $location = $city;
        if ($lat === '' || $lon === '') {
            $geo = $this->httpGetJson('https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
                'name' => preg_replace('/,\s*Vietnam$/i', '', $city),
                'count' => '1',
                'language' => 'vi',
                'format' => 'json',
            ]));
            $first = is_array($geo['results'][0] ?? null) ? $geo['results'][0] : [];
            if (empty($first['latitude']) || empty($first['longitude'])) {
                return [];
            }
            $lat = (string) $first['latitude'];
            $lon = (string) $first['longitude'];
            $location = trim((string) ($first['name'] ?? $city) . (!empty($first['country']) ? ', ' . (string) $first['country'] : ''));
        }

        $params = [
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,precipitation,rain,weather_code,pressure_msl,wind_speed_10m,wind_direction_10m',
            'hourly' => 'temperature_2m,precipitation_probability,weather_code,wind_speed_10m',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
            'timezone' => 'auto',
            'forecast_days' => '10',
        ];
        if ($unit === 'imperial') {
            $params['temperature_unit'] = 'fahrenheit';
            $params['wind_speed_unit'] = 'mph';
        } else {
            $params['wind_speed_unit'] = 'kmh';
        }

        $forecast = $this->httpGetJson('https://api.open-meteo.com/v1/forecast?' . http_build_query($params));
        $current = is_array($forecast['current'] ?? null) ? $forecast['current'] : [];
        if (!$current) {
            return [];
        }

        $weather = $this->meteoWeather((int) ($current['weather_code'] ?? 3), (int) ($current['is_day'] ?? 1));
        $hourly = [];
        $hourTimes = (array) ($forecast['hourly']['time'] ?? []);
        $hourTemps = (array) ($forecast['hourly']['temperature_2m'] ?? []);
        $hourRain = (array) ($forecast['hourly']['precipitation_probability'] ?? []);
        $hourCodes = (array) ($forecast['hourly']['weather_code'] ?? []);
        $hourWind = (array) ($forecast['hourly']['wind_speed_10m'] ?? []);
        foreach (array_slice($hourTimes, 0, 24, true) as $idx => $time) {
            $hourWeather = $this->meteoWeather((int) ($hourCodes[$idx] ?? 3), 1);
            $hourly[] = [
                'time' => date('H:00', strtotime((string) $time)),
                'icon' => $hourWeather['icon'],
                'temp' => (int) round((float) ($hourTemps[$idx] ?? 0)),
                'rain' => (int) round((float) ($hourRain[$idx] ?? 0)),
                'wind' => round((float) ($hourWind[$idx] ?? 0), 1) . ' ' . ($unit === 'imperial' ? 'mph' : 'km/h'),
            ];
        }

        $daily = [];
        $dailyTimes = (array) ($forecast['daily']['time'] ?? []);
        $dailyCodes = (array) ($forecast['daily']['weather_code'] ?? []);
        $dailyMax = (array) ($forecast['daily']['temperature_2m_max'] ?? []);
        $dailyMin = (array) ($forecast['daily']['temperature_2m_min'] ?? []);
        $dailyRain = (array) ($forecast['daily']['precipitation_probability_max'] ?? []);
        foreach (array_slice($dailyTimes, 0, 10, true) as $idx => $time) {
            $dayWeather = $this->meteoWeather((int) ($dailyCodes[$idx] ?? 3), 1);
            $daily[] = [
                'day' => date('D d/m', strtotime((string) $time)),
                'condition' => $dayWeather['label'],
                'icon' => $dayWeather['icon'],
                'min' => (int) round((float) ($dailyMin[$idx] ?? $current['temperature_2m'] ?? 0)),
                'max' => (int) round((float) ($dailyMax[$idx] ?? $current['temperature_2m'] ?? 0)),
                'rain' => (int) round((float) ($dailyRain[$idx] ?? 0)),
                'humidity' => (int) ($current['relative_humidity_2m'] ?? 0),
                'wind' => round((float) ($current['wind_speed_10m'] ?? 0), 1) . ' ' . ($unit === 'imperial' ? 'mph' : 'km/h'),
            ];
        }

        return [
            'location' => $location,
            'updated_at' => date('d/m/Y H:i', strtotime((string) ($current['time'] ?? 'now'))),
            'unit' => $unit,
            'provider' => 'Open-Meteo',
            'current' => [
                'temperature' => (int) round((float) ($current['temperature_2m'] ?? 0)),
                'condition' => $weather['label'],
                'feels_like' => (int) round((float) ($current['apparent_temperature'] ?? $current['temperature_2m'] ?? 0)),
                'humidity' => (int) ($current['relative_humidity_2m'] ?? 0),
                'wind' => round((float) ($current['wind_speed_10m'] ?? 0), 1) . ' ' . ($unit === 'imperial' ? 'mph' : 'km/h'),
                'pressure' => (string) round((float) ($current['pressure_msl'] ?? 1012)) . ' hPa',
                'visibility' => 'N/A',
                'uv' => 'N/A',
                'high' => isset($dailyMax[0]) ? (int) round((float) $dailyMax[0]) : (int) round((float) ($current['temperature_2m'] ?? 0)),
                'low' => isset($dailyMin[0]) ? (int) round((float) $dailyMin[0]) : (int) round((float) ($current['temperature_2m'] ?? 0)),
                'wind_direction' => $this->windDirection((int) ($current['wind_direction_10m'] ?? 45)),
                'rain_chance' => isset($dailyRain[0]) ? (int) $dailyRain[0] : 0,
                'sunrise' => !empty($forecast['daily']['sunrise'][0]) ? date('H:i', strtotime((string) $forecast['daily']['sunrise'][0])) : '06:00',
                'sunset' => !empty($forecast['daily']['sunset'][0]) ? date('H:i', strtotime((string) $forecast['daily']['sunset'][0])) : '18:00',
                'icon' => $weather['icon'],
            ],
            'aqi' => [
                'value' => 42,
                'label' => 'Good',
                'advice' => 'AQI cần endpoint air-quality riêng; dữ liệu thời tiết hiện tại đang lấy realtime từ Open-Meteo.',
            ],
            'map' => [
                'lat' => $lat,
                'lon' => $lon,
                'layers' => ['rain', 'clouds', 'temperature', 'wind', 'pressure', 'radar'],
            ],
            'alert' => [
                'severity' => 'normal',
                'title' => 'Không có cảnh báo thời tiết nghiêm trọng.',
                'time' => date('d/m/Y H:i'),
                'description' => 'Dữ liệu dự báo được cập nhật từ Open-Meteo. Cảnh báo khẩn cấp phụ thuộc provider cảnh báo khu vực.',
            ],
            'hourly' => $hourly,
            'daily' => $daily,
            'news' => $this->weatherNews(),
        ];
    }

    private function fetchOpenWeather(string $city, string $lat, string $lon, string $unit): array
    {
        $key = $this->openWeatherKey();
        if ($key === '') {
            return [];
        }
        $base = rtrim($this->env('OPENWEATHER_API_BASE_URL') ?: 'https://api.openweathermap.org', '/');

        if ($lat === '' || $lon === '') {
            $geo = $this->httpGetJson($base . '/geo/1.0/direct?' . http_build_query([
                'q' => $city,
                'limit' => '1',
                'appid' => $key,
            ]));
            if (empty($geo[0]['lat']) || empty($geo[0]['lon'])) {
                return [];
            }
            $lat = (string) $geo[0]['lat'];
            $lon = (string) $geo[0]['lon'];
            $city = trim((string) ($geo[0]['name'] ?? $city));
            $country = trim((string) ($geo[0]['country'] ?? ''));
        } else {
            $country = '';
        }

        $current = $this->httpGetJson($base . '/data/2.5/weather?' . http_build_query([
            'lat' => $lat,
            'lon' => $lon,
            'units' => $unit,
            'lang' => 'vi',
            'appid' => $key,
        ]));
        $forecast = $this->httpGetJson($base . '/data/2.5/forecast?' . http_build_query([
            'lat' => $lat,
            'lon' => $lon,
            'units' => $unit,
            'lang' => 'vi',
            'appid' => $key,
        ]));
        if (empty($current['main'])) {
            return [];
        }

        $weather = is_array($current['weather'][0] ?? null) ? $current['weather'][0] : [];
        $timezoneOffset = (int) ($current['timezone'] ?? 0);
        $cityName = trim((string) ($current['name'] ?? $city));
        $country = trim((string) ($country ?: ($current['sys']['country'] ?? '')));
        /* OpenWeatherMap returns hyper-local neighborhood names like "Xom Pho"
           for Hanoi-area coords. Normalise to the user-facing city. */
        $coordLat = (float) ($current['coord']['lat'] ?? $lat);
        $coordLon = (float) ($current['coord']['lon'] ?? $lon);
        if ($country === 'VN' && abs($coordLat - 21.03) < 0.25 && abs($coordLon - 105.85) < 0.35) {
            $cityName = 'Ha Noi';
        }
        $windUnit = $unit === 'imperial' ? 'mph' : 'm/s';
        $hourly = [];
        foreach (array_slice((array) ($forecast['list'] ?? []), 0, 8) as $row) {
            $hourWeather = is_array($row['weather'][0] ?? null) ? $row['weather'][0] : [];
            $hourly[] = [
                'time' => $this->formatUnixTime((int) ($row['dt'] ?? time()), $timezoneOffset, 'H:00'),
                'icon' => $this->weatherIcon((string) ($hourWeather['main'] ?? 'Clouds')),
                'temp' => (int) round((float) ($row['main']['temp'] ?? 0)),
                'rain' => (int) round(((float) ($row['pop'] ?? 0)) * 100),
                'wind' => round((float) ($row['wind']['speed'] ?? 0), 1) . ' ' . $windUnit,
            ];
        }
        $daily = $this->dailyFromOpenWeatherForecast($forecast, $timezoneOffset, $unit);
        $aqi = $this->fetchOpenWeatherAirQuality($base, $lat, $lon, $key);

        return [
            'location' => trim($cityName . ($country ? ', ' . $country : '')),
            'updated_at' => $this->formatUnixTime((int) ($current['dt'] ?? time()), $timezoneOffset, 'd/m/Y H:i'),
            'unit' => $unit,
            'provider' => 'OpenWeatherMap',
            'source_url' => 'https://openweathermap.org/',
            'current' => [
                'temperature' => (int) round((float) ($current['main']['temp'] ?? 0)),
                'condition' => (string) ($weather['description'] ?? $weather['main'] ?? 'Weather'),
                'feels_like' => (int) round((float) ($current['main']['feels_like'] ?? 0)),
                'humidity' => (int) ($current['main']['humidity'] ?? 0),
                'wind' => round((float) ($current['wind']['speed'] ?? 0), 1) . ' ' . $windUnit,
                'pressure' => (string) ($current['main']['pressure'] ?? '1012') . ' hPa',
                'visibility' => round(((float) ($current['visibility'] ?? 10000)) / 1000, 1) . ' km',
                'uv' => 'N/A',
                'high' => (int) round((float) ($current['main']['temp_max'] ?? $current['main']['temp'] ?? 0)),
                'low' => (int) round((float) ($current['main']['temp_min'] ?? $current['main']['temp'] ?? 0)),
                'wind_direction' => $this->windDirection((int) ($current['wind']['deg'] ?? 45)),
                'rain_chance' => isset($hourly[0]['rain']) ? (int) $hourly[0]['rain'] : 0,
                'sunrise' => !empty($current['sys']['sunrise']) ? $this->formatUnixTime((int) $current['sys']['sunrise'], $timezoneOffset, 'H:i') : '06:00',
                'sunset' => !empty($current['sys']['sunset']) ? $this->formatUnixTime((int) $current['sys']['sunset'], $timezoneOffset, 'H:i') : '18:00',
                'icon' => $this->weatherIcon((string) ($weather['main'] ?? 'Clouds')),
            ],
            'aqi' => $aqi ?: [
                'value' => 'N/A',
                'label' => 'Không có dữ liệu',
                'advice' => 'OpenWeatherMap Air Pollution endpoint chưa trả dữ liệu AQI cho vị trí này.',
            ],
            'map' => [
                'lat' => $lat,
                'lon' => $lon,
                'layers' => ['rain', 'clouds', 'temperature', 'wind', 'pressure', 'radar'],
            ],
            'alert' => [
                'severity' => 'normal',
                'title' => 'Không có cảnh báo thời tiết nghiêm trọng.',
                'time' => $this->formatUnixTime(time(), $timezoneOffset, 'd/m/Y H:i'),
                'description' => 'Dữ liệu hiện tại và dự báo được tải từ OpenWeatherMap. Cảnh báo chi tiết phụ thuộc gói One Call.',
            ],
            'hourly' => $hourly ?: $this->hourly((int) round((float) ($current['main']['temp'] ?? 27))),
            'daily' => $daily ?: $this->daily((int) round((float) ($current['main']['temp'] ?? 27))),
            'news' => $this->weatherNews(),
        ];
    }

    private function openWeatherKey(): string
    {
        return $this->env('OPENWEATHER_API_KEY') ?: $this->env('WEATHER_API_KEY');
    }

    private function formatUnixTime(int $timestamp, int $timezoneOffset, string $format): string
    {
        return gmdate($format, $timestamp + $timezoneOffset);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dailyFromOpenWeatherForecast(array $forecast, int $timezoneOffset, string $unit): array
    {
        $groups = [];
        $windUnit = $unit === 'imperial' ? 'mph' : 'm/s';
        foreach ((array) ($forecast['list'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $timestamp = (int) ($row['dt'] ?? time());
            $key = $this->formatUnixTime($timestamp, $timezoneOffset, 'Y-m-d');
            $main = is_array($row['main'] ?? null) ? $row['main'] : [];
            $wind = is_array($row['wind'] ?? null) ? $row['wind'] : [];
            $weather = is_array($row['weather'][0] ?? null) ? $row['weather'][0] : [];
            $temp = (float) ($main['temp'] ?? 0);
            $min = (float) ($main['temp_min'] ?? $temp);
            $max = (float) ($main['temp_max'] ?? $temp);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'day' => $this->formatUnixTime($timestamp, $timezoneOffset, 'D d/m'),
                    'condition' => (string) ($weather['description'] ?? $weather['main'] ?? 'Weather'),
                    'icon' => $this->weatherIcon((string) ($weather['main'] ?? 'Clouds')),
                    'min' => $min,
                    'max' => $max,
                    'rain' => 0,
                    'humidity' => 0,
                    'wind' => 0.0,
                ];
            }

            $groups[$key]['min'] = min((float) $groups[$key]['min'], $min);
            $groups[$key]['max'] = max((float) $groups[$key]['max'], $max);
            $groups[$key]['rain'] = max((int) $groups[$key]['rain'], (int) round(((float) ($row['pop'] ?? 0)) * 100));
            $groups[$key]['humidity'] = max((int) $groups[$key]['humidity'], (int) ($main['humidity'] ?? 0));
            $groups[$key]['wind'] = max((float) $groups[$key]['wind'], (float) ($wind['speed'] ?? 0));
        }

        return array_map(static function (array $row) use ($windUnit): array {
            $row['min'] = (int) round((float) $row['min']);
            $row['max'] = (int) round((float) $row['max']);
            $row['wind'] = round((float) $row['wind'], 1) . ' ' . $windUnit;
            return $row;
        }, array_slice(array_values($groups), 0, 5));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOpenWeatherAirQuality(string $base, string $lat, string $lon, string $key): array
    {
        $data = $this->httpGetJson($base . '/data/2.5/air_pollution?' . http_build_query([
            'lat' => $lat,
            'lon' => $lon,
            'appid' => $key,
        ]));
        $aqi = (int) ($data['list'][0]['main']['aqi'] ?? 0);
        if ($aqi < 1 || $aqi > 5) {
            return [];
        }

        $labels = [
            1 => ['value' => 25, 'label' => 'Good', 'advice' => 'Không khí tốt cho hoạt động ngoài trời.'],
            2 => ['value' => 50, 'label' => 'Fair', 'advice' => 'Chất lượng không khí chấp nhận được.'],
            3 => ['value' => 85, 'label' => 'Moderate', 'advice' => 'Người nhạy cảm nên giảm hoạt động ngoài trời kéo dài.'],
            4 => ['value' => 125, 'label' => 'Poor', 'advice' => 'Nên hạn chế vận động ngoài trời nếu có bệnh hô hấp.'],
            5 => ['value' => 175, 'label' => 'Very poor', 'advice' => 'Hạn chế ra ngoài và theo dõi cảnh báo địa phương.'],
        ];

        return $labels[$aqi];
    }

    private function hourly(int $baseTemp): array
    {
        $rows = [];
        for ($i = 0; $i < 24; $i++) {
            $rows[] = [
                'time' => gmdate('H:00', strtotime('+' . $i . ' hours')),
                'icon' => $i % 3 === 0 ? '🌦' : '☁',
                'temp' => $baseTemp + (($i % 4) - 1),
                'rain' => ($i * 7) % 60,
                'wind' => (8 + ($i % 7)) . ' km/h',
            ];
        }
        return $rows;
    }

    private function daily(int $baseTemp): array
    {
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = [
                'day' => gmdate('D d/m', strtotime('+' . $i . ' days')),
                'condition' => $i % 2 === 0 ? 'Có mây' : 'Mưa rào',
                'icon' => $i % 2 === 0 ? '⛅' : '🌧',
                'min' => $baseTemp - 4 + ($i % 2),
                'max' => $baseTemp + 2 + ($i % 3),
                'rain' => ($i * 11) % 70,
                'humidity' => 58 + (($i * 5) % 28),
                'wind' => (9 + ($i % 6)) . ' km/h',
            ];
        }
        return $rows;
    }

    private function weatherNews(): array
    {
        return [
            ['title' => 'Mưa lớn cục bộ có thể ảnh hưởng giao hàng tại một số khu vực đô thị', 'summary' => 'Các đơn vị vận chuyển thường cập nhật ETA khi thời tiết xấu kéo dài.', 'image' => 'https://images.unsplash.com/photo-1504608524841-42fe6f032b4b?auto=format&fit=crop&w=1400&q=82'],
            ['title' => 'Nắng nóng khiến nhu cầu tản nhiệt laptop và PC tăng trong mùa cao điểm', 'summary' => 'Kiểm tra vệ sinh máy, keo tản nhiệt và luồng gió để giữ hiệu năng ổn định.', 'image' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1400&q=82'],
            ['title' => 'Biến đổi khí hậu làm tăng nhu cầu dự báo thời tiết theo địa điểm', 'summary' => 'Dữ liệu thời tiết theo vị trí giúp vận hành bán lẻ và logistics chủ động hơn.', 'image' => 'https://images.unsplash.com/photo-1469474968028-56623f02e42e?auto=format&fit=crop&w=1400&q=82'],
        ];
    }

    private function env(string $key): string
    {
        // Use IntegrationConfig so pvmodern.env keys (e.g. OPENWEATHER_API_KEY) are resolved.
        return $this->integrationConfig->getString($key) ?? '';
    }

    private function httpGetJson(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }
        $ch = curl_init($url);
        if (!$ch) {
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['User-Agent: Techieworld/1.0'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($body)) {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function weatherIcon(string $main): string
    {
        return match (strtolower($main)) {
            'clear' => '☀',
            'rain', 'drizzle' => '🌧',
            'thunderstorm' => '⛈',
            'snow' => '❄',
            'mist', 'fog', 'haze' => '🌫',
            default => '☁',
        };
    }

    private function meteoWeather(int $code, int $isDay): array
    {
        return match (true) {
            $code === 0 => ['label' => 'Trời quang', 'icon' => $isDay ? '☀' : '🌙'],
            in_array($code, [1, 2], true) => ['label' => 'Ít mây', 'icon' => $isDay ? '🌤' : '☁'],
            $code === 3 => ['label' => 'Nhiều mây', 'icon' => '☁'],
            in_array($code, [45, 48], true) => ['label' => 'Sương mù', 'icon' => '🌫'],
            in_array($code, [51, 53, 55, 56, 57], true) => ['label' => 'Mưa phùn', 'icon' => '🌦'],
            in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true) => ['label' => 'Mưa', 'icon' => '🌧'],
            in_array($code, [71, 73, 75, 77, 85, 86], true) => ['label' => 'Tuyết', 'icon' => '❄'],
            in_array($code, [95, 96, 99], true) => ['label' => 'Giông bão', 'icon' => '⛈'],
            default => ['label' => 'Có mây', 'icon' => '☁'],
        };
    }

    private function windDirection(int $degrees): string
    {
        $directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        return $directions[(int) round($degrees / 45) % 8];
    }
}
