<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Api;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class News implements HttpGetActionInterface
{
    private const RSS_URL = 'https://vnexpress.net/rss/kinh-doanh.rss';

    private const CATEGORIES = [
        'all', 'general', 'business', 'technology', 'science', 'health', 'sports', 'entertainment',
        'politics', 'world', 'finance', 'ai', 'local', 'startup', 'mobile', 'gadgets',
        'cybersecurity', 'software', 'gaming', 'fintech'
    ];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory
    ) {
    }

    public function execute()
    {
        $category = strtolower(trim((string) $this->request->getParam('category', 'all')));
        $category = in_array($category, self::CATEGORIES, true) ? $category : 'all';
        $page = max(1, (int) $this->request->getParam('page', 1));
        $query = strtolower(trim((string) $this->request->getParam('q', '')));
        $region = strtolower(trim((string) $this->request->getParam('region', 'global')));
        $sort = strtolower(trim((string) $this->request->getParam('sort', 'latest')));
        $perPage = 12;

        $liveArticles = $this->fetchVnExpressBusinessRss();
        $articles = $liveArticles ?: $this->buildArticles();
        $filtered = array_values(array_filter($articles, static function (array $article) use ($category, $query): bool {
            $categorySlug = strtolower((string) $article['category_slug']);
            $businessCategories = ['all', 'general', 'business', 'finance', 'fintech', 'startup', 'local'];
            $matchesCategory = in_array($category, $businessCategories, true) || $categorySlug === $category;
            $haystack = strtolower((string) $article['title'] . ' ' . $article['summary'] . ' ' . $article['source']);
            return $matchesCategory && ($query === '' || str_contains($haystack, $query));
        }));

        $totalPages = max(1, (int) ceil(count($filtered) / $perPage));
        $page = min($page, $totalPages);
        $items = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        $result = $this->resultJsonFactory->create();
        $result->setHeader('Cache-Control', 'public, max-age=600', true);
        return $result->setData([
            'success' => true,
            'category' => $category,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => count($filtered),
            'breaking' => array_slice(array_column($articles, 'title'), 0, 6),
            'lead' => $filtered[0] ?? $articles[0],
            'top' => array_slice($filtered ?: $articles, 1, 4),
            'items' => $items,
            'popular' => array_slice($articles, 2, 5),
            'topics' => ['AI PC', 'RTX 50-series', 'Cybersecurity', 'Startup Việt', 'Fintech', 'Cloud Gaming'],
            'filters' => [
                'regions' => ['global', 'vn', 'us', 'gb', 'jp', 'kr', 'sg'],
                'sorts' => ['latest', 'popular', 'relevant'],
            ],
            'updated_at' => gmdate('d/m/Y H:i'),
            'source' => 'VnExpress Kinh doanh RSS',
            'source_url' => $this->rssUrl(),
            'mock' => !$liveArticles,
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function fetchVnExpressBusinessRss(): array
    {
        $body = $this->httpGetText($this->rssUrl());
        if ($body === '' || !function_exists('simplexml_load_string')) {
            return [];
        }

        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if (!$xml || count($xml->channel->item) === 0) {
            return [];
        }

        $normalized = [];
        foreach ($xml->channel->item as $index => $item) {
            $title = trim((string) $item->title);
            $url = trim((string) $item->link);
            if ($title === '' || $url === '') {
                continue;
            }
            $description = (string) $item->description;
            $normalized[] = [
                'category_slug' => 'business',
                'category' => 'Kinh doanh',
                'time' => $this->formatTime((string) $item->pubDate),
                'title' => $title,
                'summary' => $this->summaryFromDescription($description),
                'source' => 'VnExpress Kinh doanh',
                'author' => 'VnExpress',
                'image' => $this->imageFromItem($item, $description),
                'url' => $url,
                'id' => 'vnexpress-business-' . md5($url . $index),
            ];
        }

        return $normalized;
    }

    private function env(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGetText(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if (!$ch) {
                return '';
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['User-Agent: Techieworld/1.0 (+https://techieworld.site)'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ($code >= 200 && $code < 300 && is_string($body)) ? $body : '';
        }

        $body = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'timeout' => 8,
                'header' => "User-Agent: Techieworld/1.0 (+https://techieworld.site)\r\n",
            ],
        ]));
        return is_string($body) ? $body : '';
    }

    private function formatTime(string $iso): string
    {
        try {
            $date = new \DateTimeImmutable($iso);
            return $date->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
        } catch (\Exception) {
            return gmdate('d/m/Y');
        }
    }

    private function summaryFromDescription(string $description): string
    {
        $description = preg_replace('/<a\b[^>]*>.*?<\/a>\s*(?:<br\s*\/?>|<\/br>)?/is', '', $description) ?? $description;
        $summary = trim(html_entity_decode(strip_tags(str_replace(['</br>', '<br/>', '<br>'], ' ', $description)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $summary !== '' ? $summary : 'Bài viết đang được cập nhật mô tả.';
    }

    private function imageFromItem(\SimpleXMLElement $item, string $description): string
    {
        $enclosure = $item->enclosure;
        $image = isset($enclosure['url']) ? trim((string) $enclosure['url']) : '';
        if ($image !== '') {
            return $image;
        }
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $description, $matches)) {
            return html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return 'https://images.unsplash.com/photo-1554224155-6726b3ff858f?auto=format&fit=crop&w=1400&q=82';
    }

    private function rssUrl(): string
    {
        return $this->env('VNEXPRESS_BUSINESS_RSS_URL') ?: self::RSS_URL;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildArticles(): array
    {
        $img = [
            'ai' => 'https://images.unsplash.com/photo-1677442136019-21780ecad995?auto=format&fit=crop&w=1400&q=82',
            'gadgets' => 'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=1400&q=82',
            'cybersecurity' => 'https://images.unsplash.com/photo-1550751827-4bd374c3f58b?auto=format&fit=crop&w=1400&q=82',
            'mobile' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=1400&q=82',
            'gaming' => 'https://images.unsplash.com/photo-1542751371-adc38448a05e?auto=format&fit=crop&w=1400&q=82',
            'startup' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?auto=format&fit=crop&w=1400&q=82',
            'software' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1400&q=82',
            'fintech' => 'https://images.unsplash.com/photo-1526304640581-d334cdbbf45e?auto=format&fit=crop&w=1400&q=82',
            'science' => 'https://images.unsplash.com/photo-1581093458791-9d42e7abbd35?auto=format&fit=crop&w=1400&q=82',
            'business' => 'https://images.unsplash.com/photo-1554224155-6726b3ff858f?auto=format&fit=crop&w=1400&q=82',
            'technology' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?auto=format&fit=crop&w=1400&q=82',
            'general' => 'https://images.unsplash.com/photo-1495020689067-958852a7765e?auto=format&fit=crop&w=1400&q=82',
            'health' => 'https://images.unsplash.com/photo-1505751172876-fa1923c5c528?auto=format&fit=crop&w=1400&q=82',
            'sports' => 'https://images.unsplash.com/photo-1517649763962-0c623066013b?auto=format&fit=crop&w=1400&q=82',
            'entertainment' => 'https://images.unsplash.com/photo-1489599849927-2ee91cede3ba?auto=format&fit=crop&w=1400&q=82',
            'politics' => 'https://images.unsplash.com/photo-1529107386315-e1a2ed48a620?auto=format&fit=crop&w=1400&q=82',
            'world' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1400&q=82',
            'finance' => 'https://images.unsplash.com/photo-1520607162513-77705c0f0d4a?auto=format&fit=crop&w=1400&q=82',
            'local' => 'https://images.unsplash.com/photo-1528127269322-539801943592?auto=format&fit=crop&w=1400&q=82',
        ];
        $today = gmdate('d/m/Y');

        return [
            ['category_slug' => 'technology', 'category' => 'Technology', 'time' => $today, 'title' => 'Real-time dashboards trở thành trung tâm điều hành cho người dùng công nghệ', 'summary' => 'Tin tức, thời tiết và tỷ giá được gom trong một trải nghiệm nhanh, cá nhân hóa và dễ theo dõi trên mọi thiết bị.', 'source' => 'GlobalPulse Desk', 'image' => $img['technology'], 'url' => 'https://www.theverge.com/tech'],
            ['category_slug' => 'ai', 'category' => 'AI & Innovation', 'time' => $today, 'title' => 'AI PC bước vào giai đoạn phổ cập với NPU mạnh hơn và phần mềm tối ưu hơn', 'summary' => 'Các hãng chip đang đẩy mạnh xử lý AI cục bộ, giúp laptop và desktop tăng tốc tác vụ sáng tạo, bảo mật và tự động hóa.', 'source' => 'Techieworld Brief', 'image' => $img['ai'], 'url' => 'https://www.theverge.com/ai-artificial-intelligence'],
            ['category_slug' => 'gadgets', 'category' => 'Gadgets', 'time' => $today, 'title' => 'Màn hình OLED tần số quét cao trở thành lựa chọn chủ đạo cho gaming và creator', 'summary' => 'OLED thế hệ mới tập trung giảm burn-in, tăng độ sáng và tối ưu độ trễ cho người dùng chơi game lẫn làm việc màu sắc.', 'source' => 'Hardware Desk', 'image' => $img['gadgets'], 'url' => 'https://www.tomshardware.com/monitors'],
            ['category_slug' => 'cybersecurity', 'category' => 'Cybersecurity', 'time' => $today, 'title' => 'Bảo mật endpoint cho cửa hàng online cần ưu tiên MFA, backup và kiểm soát plugin', 'summary' => 'Các website thương mại điện tử nhỏ dễ bị tấn công qua tài khoản admin yếu, extension lỗi thời và cấu hình backup kém.', 'source' => 'Security Note', 'image' => $img['cybersecurity'], 'url' => 'https://www.bleepingcomputer.com/'],
            ['category_slug' => 'mobile', 'category' => 'Mobile', 'time' => $today, 'title' => 'Laptop mỏng nhẹ dùng chip mới tăng thời lượng pin nhưng vẫn giữ hiệu năng AI', 'summary' => 'Các mẫu ultrabook 2026 tập trung cân bằng hiệu năng, màn hình đẹp, webcam tốt và khả năng chạy mô hình AI nhỏ offline.', 'source' => 'Mobile Lab', 'image' => $img['mobile'], 'url' => 'https://www.notebookcheck.net/'],
            ['category_slug' => 'gaming', 'category' => 'Gaming', 'time' => $today, 'title' => 'GPU mới khiến cấu hình chơi game 1440p trở nên dễ tiếp cận hơn', 'summary' => 'Thị trường card đồ họa đang dịch chuyển sang tối ưu hiệu năng mỗi watt và công nghệ upscale bằng AI.', 'source' => 'Gaming Wire', 'image' => $img['gaming'], 'url' => 'https://www.pcgamer.com/hardware/graphics-cards/'],
            ['category_slug' => 'startup', 'category' => 'Startup', 'time' => $today, 'title' => 'Startup phần mềm Việt tăng nhu cầu workstation, cloud GPU và thiết bị làm việc lai', 'summary' => 'Xu hướng phát triển sản phẩm AI và SaaS kéo theo nhu cầu phần cứng ổn định cho đội ngũ kỹ thuật.', 'source' => 'Startup Radar', 'image' => $img['startup'], 'url' => 'https://techcrunch.com/category/startups/'],
            ['category_slug' => 'software', 'category' => 'Software', 'time' => $today, 'title' => 'Developer toolchain hiện đại ưu tiên local-first, automation và kiểm thử nhanh', 'summary' => 'Máy trạm cấu hình tốt giúp vòng lặp build-test-deploy ngắn hơn, đặc biệt với frontend, mobile và AI-assisted coding.', 'source' => 'Dev Stack', 'image' => $img['software'], 'url' => 'https://www.infoq.com/'],
            ['category_slug' => 'fintech', 'category' => 'Fintech', 'time' => $today, 'title' => 'Thanh toán QR tiếp tục tăng trong bán lẻ nhờ trải nghiệm checkout nhanh', 'summary' => 'Ví điện tử và ngân hàng số đang đẩy mạnh QR, deeplink và xác thực giao dịch ngay trên ứng dụng.', 'source' => 'Fintech Watch', 'image' => $img['fintech'], 'url' => 'https://fintechnews.sg/'],
            ['category_slug' => 'science', 'category' => 'Science', 'time' => $today, 'title' => 'Vật liệu bán dẫn và đóng gói chip tiên tiến định hình thế hệ phần cứng mới', 'summary' => 'Nhu cầu AI làm tăng đầu tư vào bộ nhớ băng thông cao, chiplet và hệ thống tản nhiệt hiệu quả hơn.', 'source' => 'Science Hardware', 'image' => $img['science'], 'url' => 'https://spectrum.ieee.org/semiconductors'],
            ['category_slug' => 'business', 'category' => 'Business', 'time' => $today, 'title' => 'Doanh nghiệp bán lẻ công nghệ tập trung hậu mãi, bảo hành và tracking đơn hàng', 'summary' => 'Trải nghiệm sau mua hàng đang trở thành điểm cạnh tranh quan trọng bên cạnh giá và danh mục sản phẩm.', 'source' => 'Commerce Brief', 'image' => $img['business'], 'url' => 'https://www.retaildive.com/'],
            ['category_slug' => 'general', 'category' => 'General', 'time' => $today, 'title' => 'Các nền tảng thông tin cá nhân hóa tăng tốc nhờ dữ liệu theo thời gian thực', 'summary' => 'Người dùng kỳ vọng dashboard cập nhật nhanh, có lưu yêu thích, cảnh báo và tìm kiếm toàn cục.', 'source' => 'Global Newsroom', 'image' => $img['general'], 'url' => 'https://www.bbc.com/news/technology'],
            ['category_slug' => 'health', 'category' => 'Health', 'time' => $today, 'title' => 'Thiết bị đeo sức khỏe mới tập trung cảnh báo sớm và theo dõi chất lượng giấc ngủ', 'summary' => 'Cảm biến tốt hơn giúp người dùng hiểu rõ nhịp tim, stress và mức vận động mỗi ngày.', 'source' => 'Health Tech', 'image' => $img['health'], 'url' => 'https://www.wired.com/tag/health/'],
            ['category_slug' => 'sports', 'category' => 'Sports', 'time' => $today, 'title' => 'Phân tích dữ liệu thời gian thực thay đổi cách đội thể thao tối ưu hiệu suất', 'summary' => 'Wearable, camera và mô hình dự báo đang được dùng để giảm chấn thương và cải thiện chiến thuật.', 'source' => 'Sports Data', 'image' => $img['sports'], 'url' => 'https://www.espn.com/'],
            ['category_slug' => 'entertainment', 'category' => 'Entertainment', 'time' => $today, 'title' => 'Streaming và gaming cloud tiếp tục cạnh tranh bằng nội dung độc quyền', 'summary' => 'Hạ tầng mạng tốt hơn giúp dịch vụ giải trí cá nhân hóa và hoạt động mượt trên thiết bị di động.', 'source' => 'Media Pulse', 'image' => $img['entertainment'], 'url' => 'https://www.theverge.com/entertainment'],
            ['category_slug' => 'politics', 'category' => 'Politics', 'time' => $today, 'title' => 'Chính sách dữ liệu và AI trở thành ưu tiên trong chương trình nghị sự công nghệ', 'summary' => 'Các quốc gia tăng cường quy định về quyền riêng tư, an toàn AI và trách nhiệm nền tảng số.', 'source' => 'Policy Watch', 'image' => $img['politics'], 'url' => 'https://www.politico.com/technology'],
            ['category_slug' => 'world', 'category' => 'World', 'time' => $today, 'title' => 'Chuỗi cung ứng toàn cầu dịch chuyển theo nhu cầu chip, pin và thiết bị AI', 'summary' => 'Các trung tâm sản xuất mới đang được đầu tư để giảm rủi ro logistics và đáp ứng nhu cầu phần cứng.', 'source' => 'World Tech', 'image' => $img['world'], 'url' => 'https://www.reuters.com/technology/'],
            ['category_slug' => 'finance', 'category' => 'Finance', 'time' => $today, 'title' => 'Tỷ giá và lãi suất tiếp tục ảnh hưởng giá nhập khẩu thiết bị công nghệ', 'summary' => 'Doanh nghiệp theo dõi ngoại hối để điều chỉnh giá bán, tồn kho và chương trình khuyến mãi.', 'source' => 'Finance Wire', 'image' => $img['finance'], 'url' => 'https://www.bloomberg.com/technology'],
            ['category_slug' => 'local', 'category' => 'Local News', 'time' => $today, 'title' => 'Tin địa phương ưu tiên giao thông, thời tiết và dịch vụ tiêu dùng quanh vị trí người dùng', 'summary' => 'Dashboard theo vị trí giúp người dùng nắm các cảnh báo gần mình nhanh hơn.', 'source' => 'Local Pulse', 'image' => $img['local'], 'url' => 'https://vnexpress.net/so-hoa'],
        ];
    }
}
