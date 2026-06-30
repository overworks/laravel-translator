# laravel-translator

[English](README.md) | **한국어**

여러 번역 서비스(DeepL, Google Cloud Translation, LLM 등)를 **하나의 통일된 API**로 사용하는 Laravel 패키지입니다.
`config/filesystems.php`와 동일한 형태입니다: 이름을 붙인 **translator**를 정의하고, 각 항목이 **`driver`** 키로 구현을 고릅니다. `Storage::disk('name')`처럼 `Translator::via('name')`으로 선택합니다. 번역 결과 캐싱을 기본 제공합니다.

내장 드라이버:

- **`deepl`** — DeepL
- **`google`** — Google Cloud Translation (v2)
- **`claude`** — 네이티브 Anthropic Messages API ([mozex/anthropic-php](https://github.com/mozex/anthropic-php) 기반)
- **`openai`** — OpenAI 및 모든 OpenAI 호환 엔드포인트(DeepSeek, Gemini, Groq, Mistral, xAI, OpenRouter, Ollama, 사내 게이트웨이)를 `base_url`로 지정 ([openai-php/client](https://github.com/openai-php/client) 기반)
- **`fallback`** — 여러 translator를 순서대로 시도

무거운 LLM 추상화 레이어 없이, 각 드라이버가 프로바이더 SDK/API에 직접 요청합니다.

## 요구 사항

- PHP `^8.3`
- Laravel 12 / 13 (`illuminate/support: ^12.0|^13.0`)

> Google 드라이버는 **Translation API v2**를 사용해 **API 키만으로** 동작합니다. 서비스 계정 자격증명이나 `ext-grpc` PECL 확장이 필요 없습니다.

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

# 캐싱
TRANSLATOR_CACHE=true
TRANSLATOR_CACHE_STORE=          # 비우면 기본 스토어 사용
TRANSLATOR_CACHE_TTL=86400       # 초 단위. 비우면 영구 캐시
```

> 배치 번역은 모델에 JSON 객체를 요청해 입력 개수와 순서를 보장하며, 개수가 맞지 않으면 예외를 던집니다.

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

```php
Translator::via('claude')->translate('Hello', 'ko'); // 결과의 ->translator 는 "claude"
```

## 사용법

### 단건 번역

```php
use Minhyung\LaravelTranslator\Facades\Translator;

$result = Translator::translate('Hello, world!', 'ko');

$result->text;               // "안녕하세요, 여러분!"
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

### translator 선택

```php
Translator::via('google')->translate('Hello', 'ko');

// 호출 단위 옵션 전달
Translator::via('openai')->translate('Hello', 'ko', 'en', [
    'temperature'   => 0.0,
    'system_prompt' => 'Translate from {source} into {target}. Keep it formal.',
]);
```

### 의존성 주입

`Translator` 계약(contract)은 기본 translator로 바인딩되어 있습니다.

```php
use Minhyung\LaravelTranslator\Contracts\Translator;

public function __construct(private Translator $translator) {}
```

## 캐싱

`translator.cache.enabled`가 켜져 있으면 모든 driver가 `CachingDriver`로 감싸집니다.
동일한 입력(텍스트 · 소스/타깃 언어 · 옵션)은 Laravel 캐시에서 즉시 반환되어 API 호출과 비용을 줄입니다.
배치 번역 시에는 **캐시 미스 항목만** 모아 한 번에 호출합니다.

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

- 각 자식 translator는 **개별적으로 캐싱**되며(`fallback` 자체는 이중 캐시를 피하기 위해 캐싱하지 않음), 전환 시도는 PSR 로거로 `warning` 로깅됩니다.
- 모든 translator가 실패하면 `AllTranslationDriversFailedException`이 발생하고, `getErrors()`로 translator별 원인 예외를 얻을 수 있습니다.

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

```bash
composer install
vendor/bin/pest
```

## License

MIT
