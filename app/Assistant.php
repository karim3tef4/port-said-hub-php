<?php
declare(strict_types=1);
namespace App;

/**
 * مساعد بورسعيد: شات بوت بيرد بذكاء اصطناعي حقيقي (Anthropic Claude) باستخدام مفتاح API بتاع صاحب الموقع.
 * المفتاح بيتحط في config.local.php تحت 'ai' => ['api_key' => '...']. لو المفتاح فاضي، الواجهة بترجع
 * تلقائيًا للأسئلة الجاهزة (FAQ) من غير ما تكسر حاجة.
 */
final class Assistant
{
    private const SYSTEM = <<<'TXT'
إنت "مساعد بورسعيد"، مساعد ذكي جوه موقع Port Said Hub (تطبيق سياحة وتخفيضات لمدينة بورسعيد، صممه فريق Alpha).
اتكلم باللهجة المصرية العامية، بشكل ودود ومباشر ومختصر (سطرين لتلاتة عادةً، إلا لو الموضوع فعلًا محتاج تفصيل أكتر).
الموقع بيدي للمستخدم: دليل بورسعيد (معالم وترفيه، مطاعم، كافيهات، حلويات، تسوق، رياضة)، ونقاط بتتحسب بس لما يزور معلم سياحي أو مكان ترفيه
ويسجّل زيارته بالموقع، وفي صفحة كل مطعم أو محل زر "زرت المكان" وبيظهر فيها منيو المحل والعروض لو التاجر رفعها،
قسم "استكشف بورسعيد"، قسم "سكن (شقق)" (لسه محتواه جاي قريب)، قسم "الأوتيلات والفنادق" للي جاي يقضي يوم أو أسبوع،
وقسم "مواصلات" فيه خطوط المينى باص والفيري بين أحياء المدينة. وفيه جرس إشعارات جنب أيقونة الحساب بتوصله فيه رسايل الإدارة وعروض المحلات.
أصحاب المحلات يقدروا يسجلوا متجرهم من صفحة إنشاء الحساب (كحساب تاجر) ويبقى ليهم حساب منفصل تمامًا عن حساب الزائر العادي،
وبيحددوا فيه نسبة الخصم اللي هيديها للعميل والعمولة لفريق Alpha، وبعدين فريق الإدارة بيراجع الطلب ويوافق عليه.
لو حد سألك سؤال برا نطاق الموقع، جاوب بأدب لكن رجّعه لموضوع الموقع. لو مش متأكد من معلومة محددة (زي نسبة خصم مكان بعينه
أو تفاصيل حساب حد)، قول بصراحة إنك مش متأكد واقترح يستخدم زرار "شكاوى واقتراحات" فوق الصفحة عشان يتواصل مع الفريق،
وماتختلقش إجابة. ماتديش نصايح طبية أو قانونية أو مالية.
TXT;

    public static function ask(): void
    {
        $u = Auth::need();
        RateLimiter::hit('assistant:' . $u['id'], 30, 3600);
        $b = Http::body();
        $msg = Validator::str($b['message'] ?? null, 1, 600, 'الرسالة');
        $historyIn = is_array($b['history'] ?? null) ? array_slice($b['history'], -8) : [];

        $key = trim((string)Config::get('ai.api_key', ''));
        if ($key === '' || !function_exists('curl_init')) {
            Http::ok(['ai_enabled' => false, 'reply' => null]);
            return;
        }

        $messages = [];
        foreach ($historyIn as $turn) {
            if (!is_array($turn)) { continue; }
            $role = (($turn['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $text = isset($turn['text']) && is_string($turn['text']) ? trim((string)preg_replace('/\s+/u', ' ', $turn['text'])) : '';
            if ($text !== '' && mb_strlen($text) <= 600) { $messages[] = ['role' => $role, 'content' => $text]; }
        }
        $messages[] = ['role' => 'user', 'content' => $msg];

        $payload = json_encode([
            'model' => (string)Config::get('ai.model', 'claude-sonnet-4-5'),
            'max_tokens' => 400,
            'system' => self::SYSTEM,
            'messages' => $messages,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) { throw new ApiException('server', 'تعذّر تجهيز الرسالة.', 500); }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $res = curl_exec($ch);
        $curlErr = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $curlErr !== '' || $code >= 300) {
            Audit::log($u['id'], 'assistant.error', 'assistant', null, ['code' => $code, 'curl' => $curlErr !== '' ? $curlErr : null]);
            Http::ok(['ai_enabled' => true, 'reply' => null]);
            return;
        }
        $data = json_decode((string)$res, true);
        $text = '';
        if (is_array($data)) {
            foreach (($data['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) { $text .= (string)$block['text']; }
            }
        }
        $text = trim($text);
        Http::ok(['ai_enabled' => true, 'reply' => $text !== '' ? $text : null]);
    }
}
