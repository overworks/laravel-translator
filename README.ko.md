# laravel-translator

[English](README.md) | **한국어**

여러 번역 서비스(DeepL, Google Cloud Translation, LLM 등)를 **하나의 통일된 API**로 사용하는 Laravel 패키지입니다.
config에 이름을 붙인 **translator**를 정의하고, 각 항목이 **`driver`** 키로 구현을 고릅니다. `Translator::via('name')`으로 선택하며, 번역 결과 캐싱을 기본 제공합니다.

내장 드라이버:

- **`deepl`** — DeepL
- **`google`** — Google Cloud Translation (기본 v2, `version`으로 v3/Advanced)
- **`claude`** — 네이티브 Anthropic Messages API ([mozex/anthropic-php](https://github.com/mozex/anthropic-php) 기반)
- **`openai`** — OpenAI 및 모든 OpenAI 호환 엔드포인트(DeepSeek, Gemini, Groq, Mistral, xAI, OpenRouter, Ollama, 사내 게이트웨이)를 `base_url`로 지정 ([openai-php/client](https://github.com/openai-php/client) 기반)
- **`azure`** — Azure AI Translator (Translator REST API v3.0)
- **`amazon`** — Amazon Translate ([aws/aws-sdk-php](https://github.com/aws/aws-sdk-php) 기반, 선택적 의존성)
- **`libretranslate`** — [LibreTranslate](https://libretranslate.com) (무료·오픈소스, 셀프호스트 또는 호스팅)
- **`fallback`** — 여러 translator를 순서대로 시도

무거운 LLM 추상화 레이어 없이, 각 드라이버가 프로바이더 SDK/API에 직접 요청합니다.

## 요구 사항

- PHP `^8.3`
- Laravel 12 / 13 (`illuminate/support: ^12.0|^13.0`)

> Google 드라이버는 기본적으로 **Translation API v2**를 사용해 **API 키만으로** 동작합니다(서비스 계정 자격증명·`ext-grpc` PECL 확장 불필요). **v3(Advanced)**는 `'version' => 3`으로 켤 수 있으며 서비스 계정 / Application Default Credentials로 인증합니다(REST 전용 — gRPC 여전히 불필요).

## 설치

```bash
composer require minhyung/laravel-translator
```

설정 파일 publish (선택):

```bash
php artisan vendor:publish --tag=translator-config
```

## 설정

`config/translator.php` 또는 `.env`:

```dotenv
TRANSLATOR_DEFAULT=deepl        # 기본 translator 이름: deepl | google | claude | openai | ...

# DeepL
DEEPL_AUTH_KEY=xxxxxxxx:fx

# Google Cloud Translation (v2, API key)
GOOGLE_TRANSLATE_KEY=AIza...

# Claude (네이티브)
ANTHROPIC_API_KEY=sk-ant-...
TRANSLATOR_CLAUDE_MODEL=claude-haiku-4-5

# OpenAI + 호환 프로바이더 — API 키 + 선택 모델 지정
OPENAI_API_KEY=sk-...
TRANSLATOR_OPENAI_MODEL=gpt-5.4-mini
GEMINI_API_KEY=AIza...
TRANSLATOR_GEMINI_MODEL=gemini-3-flash-preview
DEEPSEEK_API_KEY=sk-...

# Azure AI Translator
AZURE_TRANSLATOR_KEY=xxxxxxxx
AZURE_TRANSLATOR_REGION=koreacentral   # 글로벌 키는 생략 가능

# Amazon Translate (aws/aws-sdk-php 필요; key/secret 생략 시 AWS 자격 증명 체인 사용)
AWS_DEFAULT_REGION=us-east-1
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...

# 캐싱
TRANSLATOR_CACHE=true
TRANSLATOR_CACHE_STORE=          # 비우면 기본 스토어 사용
TRANSLATOR_CACHE_TTL=86400       # 초 단위. 비우면 영구 캐시
```

### translator 정의

`translators` 아래 각 항목은 `driver` 키로 구현을 고르는, 이름 붙인 인스턴스입니다.
여러 이름이 하나의 driver를 공유할 수 있습니다 — 예를 들어 DeepSeek와 Gemini는 각자의 `base_url`로 `openai` driver를 씁니다:

```php
// config/translator.php
'translators' => [
    'deepl'  => ['driver' => 'deepl',  'key' => env('DEEPL_AUTH_KEY')],
    'google' => ['driver' => 'google', 'key' => env('GOOGLE_TRANSLATE_KEY')],
    'claude' => ['driver' => 'claude', 'key' => env('ANTHROPIC_API_KEY'), 'model' => 'claude-haiku-4-5'],
    'openai' => ['driver' => 'openai', 'key' => env('OPENAI_API_KEY'), 'model' => 'gpt-5.4-mini'],

    // OpenAI 호환 엔드포인트: 같은 driver, 다른 base_url
    'gemini' => [
        'driver'   => 'openai',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'key'      => env('GEMINI_API_KEY'),
        'model'    => 'gemini-3-flash-preview',
    ],
    'deepseek' => [
        'driver'   => 'openai',
        'base_url' => 'https://api.deepseek.com/v1',
        'key'      => env('DEEPSEEK_API_KEY'),
        'model'    => 'deepseek-v4-flash',
        'options'  => [
            // DeepSeek V4는 "thinking"이 기본 ON이라, 번역용으로 끕니다.
            'extra_body' => ['thinking' => ['type' => 'disabled']],
        ],

        // 'headers' => ['X-Tenant' => 'acme'], // 추가 HTTP 헤더
    ],
],
```

`openai` driver의 `options`는 `temperature`, `max_tokens`, `system_prompt`, 그리고 `extra_body`(OpenAI SDK의 `extra_body`처럼 요청 본문 최상위에 병합되는 임의 필드 — 위에서 DeepSeek thinking 모드를 끄는 데 사용)를 받습니다.

`openai` driver용 주요 `base_url`: DeepSeek `https://api.deepseek.com/v1`, Gemini `https://generativelanguage.googleapis.com/v1beta/openai`, Groq `https://api.groq.com/openai/v1`, Mistral `https://api.mistral.ai/v1`, xAI `https://api.x.ai/v1`, OpenRouter `https://openrouter.ai/api/v1`, Ollama `http://localhost:11434/v1`.

`libretranslate` driver는 `base_url`(기본 `https://libretranslate.com`)과 선택적 `key`를 받습니다 — 키는 키 기반 인스턴스에서만 필요합니다:

```php
'libretranslate' => [
    'driver'   => 'libretranslate',
    'base_url' => env('LIBRETRANSLATE_URL', 'http://localhost:5000'),
    'key'      => env('LIBRETRANSLATE_API_KEY'), // 선택
],
```

`google` driver는 두 API 버전 모두 `google`로 두고 `version`으로 고릅니다. v2(기본)는 API `key`, v3(Advanced)는 `project_id`(+ 선택 `location`)와 서비스 계정 / ADC 인증을 사용합니다:

```php
'google' => [
    'driver'      => 'google',
    'version'     => 3,
    'project_id'  => env('GOOGLE_CLOUD_PROJECT'),
    'location'    => 'global',
    'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'), // 서비스 계정 JSON 경로; null이면 ADC
],
```

`azure` driver는 구독 `key`를 받습니다. 지역(regional)·멀티서비스 리소스는 `region`이 필요하며(글로벌·단일서비스 키는 생략 가능), 소버린 클라우드는 `endpoint`로 재정의합니다:

```php
'azure' => [
    'driver' => 'azure',
    'key'    => env('AZURE_TRANSLATOR_KEY'),
    'region' => env('AZURE_TRANSLATOR_REGION'), // 예: "koreacentral"; 글로벌 키는 생략 가능
],
```

`amazon` driver는 AWS SDK(`composer require aws/aws-sdk-php`)와 `region`이 필요합니다. `key`/`secret`을 생략하면 AWS 기본 자격 증명 체인(환경변수, `~/.aws`, IAM 인스턴스/태스크 역할 등)을 사용합니다:

```php
'amazon' => [
    'driver' => 'amazon',
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'key'    => env('AWS_ACCESS_KEY_ID'),     // 선택
    'secret' => env('AWS_SECRET_ACCESS_KEY'), // 선택
],
```

```php
Translator::via('claude')->translate('Hello', 'ko'); // 결과의 ->translator 는 "claude"
```

## 사용법

### 단건 번역

```php
use Minhyung\LaravelTranslator\Facades\Translator;

$result = Translator::translate('Hello, world!', 'ko');

$result->text;               // "안녕하세요, 세상!"
$result->detectedSourceLang; // "en"
$result->translator;         // "deepl"
(string) $result;            // 번역문 (Stringable)
```

소스 언어 지정 및 옵션 전달:

```php
Translator::translate('How are you?', 'de', 'en', ['formality' => 'less']);
```

### 배치 번역 (키/순서 보존)

```php
$results = Translator::translateBatch(
    ['greeting' => 'Hello', 'farewell' => 'Goodbye'],
    'ko',
);

$results['greeting']->text; // "안녕하세요"
$results['farewell']->text; // "안녕히 가세요"
```

> LLM 드라이버(`claude`, `openai`)는 배치 번역 시 입력당 하나의 결과를 순서대로 담은 JSON 객체를 모델에 요청하며, 개수가 맞지 않으면 예외를 던집니다. `deepl`·`google`·`azure`·`libretranslate`는 배치를 네이티브로 처리하고, `amazon`(실시간 API가 한 번에 한 건)은 루프로 처리하되 순서와 키를 항상 보존합니다.

### 여러 언어로 한 번에

```php
$results = Translator::translateInto(['ko', 'ja', 'es'], 'Hello'); // 타깃별 키

$results['ko']->text; // "안녕하세요"
$results['ja']->text; // "こんにちは"
```

### translator 선택

```php
Translator::via('google')->translate('Hello', 'ko');

// 호출 단위 옵션 전달
Translator::via('openai')->translate('Hello', 'ko', 'en', [
    'temperature'   => 0.0,
    'system_prompt' => 'Translate from {source} into {target}. Keep it formal.',
]);
```

### 런타임에 translator 빌드

config에 없는 translator가 필요하다면(예: 테넌트별 자격증명) inline config 배열(config 항목과 동일한 형태)로 즉석 생성할 수 있습니다. 결과는 일반 `Translator`이며 캐싱되지 않습니다:

```php
$translator = Translator::build([
    'driver' => 'openai',
    'base_url' => 'https://api.deepseek.com/v1',
    'key'   => $tenant->deepseek_key,
    'model' => 'deepseek-v4-flash',
]);

$translator->translate('Hello', 'ko');
```

두 번째 인자로 이름을 줄 수 있습니다(결과의 `->translator`와 이벤트에 사용).

### 의존성 주입

`Contracts\Translator` 계약(contract)은 기본 translator로 바인딩되어 있습니다.

```php
use Minhyung\LaravelTranslator\Contracts\Translator;

public function __construct(private Translator $translator) {}
```

## 언어 감지

언어 감지를 지원하는 드라이버 — `google`(v2·v3), `azure`, `libretranslate` — 는 `detect()`를 제공합니다:

```php
use Minhyung\LaravelTranslator\Facades\Translator;

$detection = Translator::via('google')->detect('Bonjour le monde');

$detection->language;   // "fr"
$detection->confidence; // 0.98 (프로바이더가 제공하면 0–1)
(string) $detection;    // "fr"
```

감지도 번역과 마찬가지로 캐싱·재시도·fallback을 거칩니다. 감지를 지원하지 않는 드라이버(`deepl`, `openai` 등)에 `detect()`를 호출하면 명확한 예외를 던집니다.

### 지원 언어 목록

언어 목록을 제공하는 드라이버 — `deepl`, `google`(v2·v3), `azure`, `amazon`, `libretranslate` — 는 `languages()`를 제공합니다:

```php
foreach (Translator::via('deepl')->languages() as $language) {
    $language->code;   // "EN-US"
    $language->name;   // "English (American)"
    $language->source; // 소스 언어로 사용 가능?
    $language->target; // 타깃 언어로 사용 가능?
}
```

## 커맨드라인

터미널에서 바로 번역할 수 있습니다:

```bash
php artisan translator:translate "Hello, world!" ko
# 안녕하세요, 세상!

# translator와 소스 언어 지정
php artisan translator:translate "Hello" ko --via=claude --from=en

# 전체 결과(번역문, 감지된 소스 언어, translator 등)를 JSON으로
php artisan translator:translate "Hello" ko --json
```

옵션: `--from`(소스 언어, 생략 시 자동 감지), `--via`(translator 이름, 생략 시 기본값), `--json`(전체 결과를 JSON으로 출력).

**언어파일**(PHP 그룹 + JSON)을 다른 로케일로 번역합니다 — 배열 구조, `:placeholder` 토큰, 복수형(`apple|apples`, `{1} :count …`)을 보존합니다. 기본은 누락된 키만 채우므로 다시 실행해도 안전합니다:

```bash
php artisan translator:lang ko ja            # en → ko, ja
php artisan translator:lang de --via=deepl   # 특정 translator 사용
php artisan translator:lang ko --overwrite   # 기존 키도 다시 번역
```

옵션: `--source`(소스 로케일, 기본 `en`), `--via`(translator), `--overwrite`(이미 있는 키도 재번역).

설정이 올바른지 점검합니다 — 각 translator를 빌드·검증해(키/모델 누락, 알 수 없는 `fallback` 자식, 정의되지 않은 기본값 등) 표로 보고합니다:

```bash
php artisan translator:doctor

# 각 translator로 실제 번역까지 시도
php artisan translator:doctor --ping
```

문제가 있으면 비정상 종료코드를 반환하므로 CI에서도 활용할 수 있습니다.

## 캐싱

`translator.cache.enabled`가 켜져 있으면 모든 driver가 `CachingDriver`로 감싸집니다.
동일한 입력(텍스트 · 소스/타깃 언어 · 옵션)은 Laravel 캐시에서 즉시 반환되어 API 호출과 비용을 줄입니다.
배치 번역 시에는 **캐시 미스 항목만** 모아 한 번에 호출합니다.

## 재시도

어떤 translator든 `retry` 키로 일시적 프로바이더 오류(타임아웃, 429/5xx)를 흘려보낼 수 있습니다 — 시도 횟수(int) 또는 `['times' => , 'sleep' => ]`(sleep은 ms 단위 기본 백오프, 시도 횟수에 비례):

```php
'openai' => [
    'driver' => 'openai',
    'key'    => env('OPENAI_API_KEY'),
    'model'  => 'gpt-5.4-mini',
    'retry'  => ['times' => 3, 'sleep' => 200], // 또는: 'retry' => 3
],
```

재시도는 캐싱 **안쪽**에 위치하며(캐시 히트는 재시도하지 않음) translator별로 적용됩니다 — `fallback`의 각 자식에도 적용되어, 체인이 넘어가기 전에 프로바이더가 스스로 회복할 기회를 줍니다.

## Failover

특정 프로바이더가 장애를 일으킬 때 다음으로 자동 전환하려면 `fallback` driver로 translator를 정의합니다.
나열한 translator를 순서대로 시도하고, 예외를 던지면 다음으로 넘어갑니다.

```php
// config/translator.php
'default' => 'safe',

'translators' => [
    'deepl'  => ['driver' => 'deepl',  'key' => env('DEEPL_AUTH_KEY')],
    'claude' => ['driver' => 'claude', 'key' => env('ANTHROPIC_API_KEY'), 'model' => 'claude-haiku-4-5'],

    'safe' => [
        'driver'      => 'fallback',
        'translators' => ['deepl', 'claude'],
    ],
],
```

```php
Translator::translate('Hello', 'ko'); // deepl 실패 시 claude 시도
```

- 각 자식 translator는 **개별적으로 캐싱**되며(`fallback` 자체는 이중 캐시를 피하기 위해 캐싱하지 않음), 전환 시도는 PSR 로거로 `warning` 로깅되고 `TranslationFellBack` 이벤트를 디스패치합니다.
- 모든 translator가 실패하면 `AllTranslationDriversFailedException`이 발생하고, `getErrors()`로 translator별 원인 예외를 얻을 수 있습니다.

## 큐 기반 비동기 번역

인라인 대신 백그라운드에서 번역하려면 `queue()` / `queueBatch()`를 사용합니다. 각각 `TranslateJob`을 큐에 디스패치합니다:

```php
Translator::queue('Hello', 'ko', 'en');                 // 기본 translator
Translator::via('deepl')->queue('Hello', 'ko');         // 특정 translator
Translator::via('deepl')->queueBatch(['Hello', 'Bye'], 'ko');
```

Job은 워커에서 이름으로 translator를 다시 해석하므로 항상 현재 설정과 캐싱/재시도 래핑을 사용합니다. 작업이 백그라운드에서 실행되므로 결과는 반환되지 않고 [라이프사이클 이벤트](#이벤트)로 전달됩니다 — `TranslationCompleted` / `BatchTranslationCompleted`를 리스닝해 처리하세요. `TranslationFailed`는 매 실패 시도마다가 아니라 큐가 재시도를 모두 소진한 뒤(Job의 `tries` 또는 워커의 `--tries` 기준) 한 번 디스패치되어 최종 실패를 반영합니다.

Job의 connection·queue·`tries`·`backoff`는 `translator.queue` 설정에서 옵니다 — 번역 작업을 전용 큐/커넥션으로 라우팅하려면 거기서 지정하세요. 테스트에서는 큐를 페이크하고 푸시 여부를 단언합니다:

```php
use Illuminate\Support\Facades\Queue;
use Minhyung\LaravelTranslator\Jobs\TranslateJob;

Queue::fake();
Translator::queue('Hello', 'ko');
Queue::assertPushed(TranslateJob::class);
```

## 이벤트

라이프사이클 이벤트를 디스패치하므로 리스닝할 수 있습니다:

| 이벤트 | 시점 |
| --- | --- |
| `Events\TranslationCompleted` | 단건 `translate()` 성공 (`->translator`, `->text`, `->result`, `->sourceLang`, `->options`) |
| `Events\BatchTranslationCompleted` | `translateBatch()` 성공 (`->translator`, `->texts`, `->results`, `->targetLang`, ...) |
| `Events\TranslationFailed` | 요청 최종 실패 (`->translator`, `->exception`, `->texts`, ...) |
| `Events\TranslationFellBack` | `fallback` 자식이 예외를 던져 다음으로 넘어감 (`->translator`, `->exception`) |

```php
use Illuminate\Support\Facades\Event;
use Minhyung\LaravelTranslator\Events\TranslationCompleted;

Event::listen(function (TranslationCompleted $event) {
    logger()->info("{$event->translator}로 번역: {$event->result->text}");
});
```

## 구조

두 계층으로 나뉩니다:

- **`Contracts\Driver`** — 저수준 프로바이더 계약. 각 프로바이더가 이를 구현하는 어댑터(`DeeplDriver`, `OpenAiDriver`, ...)이고, 합성 드라이버 `CachingDriver`·`FallbackDriver`도 이를 구현합니다.
- **`Translator`** (`Contracts\Translator` 구현) — 매니저의 `via()`가 돌려주고 DI에 바인딩되는 공개 객체. `Driver`를 감싸 위임하며 `->driver()`, `->name()`을 제공합니다.

즉 `Translator::via('claude')`는 (캐시로 감싼) `ClaudeDriver`를 감싼 `Translator`를 반환합니다.

## 커스텀 driver 확장

`Contracts\Driver`를 구현하고 매니저에 등록합니다. 콜백은 `($container, $name, $config)`를 받아 `Driver`를 반환하며, config에서 `'driver' => 'papago'`로 참조합니다.

```php
use Minhyung\LaravelTranslator\TranslatorManager;

app(TranslatorManager::class)->extend('papago', function ($container, $name, $config) {
    return new \App\Translation\PapagoDriver($config['key'], $name); // Contracts\Driver 구현
});
```

```php
// config/translator.php
'translators' => [
    'papago' => ['driver' => 'papago', 'key' => env('PAPAGO_KEY')],
],
```

## 테스트

앱 테스트에서 `Translator::fake()`를 호출하면 실제 프로바이더를 호출하지 않습니다. 모든 번역을 기록하고 정해진 결과를 반환하며, 기본은 소스 텍스트를 그대로 echo합니다. 맵이나 클로저로 출력을 제어할 수 있습니다:

```php
use Minhyung\LaravelTranslator\Facades\Translator;

$fake = Translator::fake([
    'Hello' => '안녕하세요',           // source => translation 맵 (없는 텍스트는 echo)
]);
// 또는: Translator::fake(fn (string $text, string $target) => "[$target] $text");

// ... 번역하는 코드 실행 ...

$fake->assertTranslated('Hello');
$fake->assertTranslated('Hello', fn (array $r) => $r['target'] === 'ko'); // 조건 매칭
$fake->assertTranslatedTimes('Hello', 1);
$fake->assertNotTranslated('Goodbye');
$fake->assertNothingTranslated();
$fake->assertTranslatedCount(1);
```

`Translator::fake()`는 컨테이너의 매니저를 교체하므로, 파사드·`Translator::via()`/`build()`·주입된 `Contracts\Translator` 모두 fake를 통해 기록됩니다.

### 기여

```bash
composer install
vendor/bin/pest
```

## License

MIT
