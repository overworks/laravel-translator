# laravel-translator

[English](README.md) | **한국어**

여러 번역 서비스(DeepL, Google Cloud Translation, LLM 등)를 **하나의 통일된 API**로 사용하는 Laravel 패키지입니다.
Laravel 표준 Manager/Driver 패턴으로 설계되어 드라이버를 쉽게 추가/교체할 수 있고, 번역 결과 캐싱을 기본 제공합니다.

지원 드라이버:

- **DeepL**
- **Google Cloud Translation (v2)**
- **Anthropic / Claude** — 네이티브 Messages API ([mozex/anthropic-php](https://github.com/mozex/anthropic-php) 기반)
- **OpenAI 호환 엔드포인트** ([openai-php/client](https://github.com/openai-php/client) 기반) — 잘 알려진 프로바이더(OpenAI, Gemini, DeepSeek, Groq, Mistral, xAI, OpenRouter, Ollama)는 이름만으로 동작하고, 그 외 엔드포인트는 `base_uri`로 지정.

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
TRANSLATOR_DRIVER=deepl        # 기본 드라이버: deepl | google | anthropic | openai | ...

# DeepL
DEEPL_AUTH_KEY=xxxxxxxx:fx

# Google Cloud Translation (v2, API key)
GOOGLE_TRANSLATE_KEY=AIza...

# Anthropic / Claude (네이티브)
ANTHROPIC_API_KEY=sk-ant-...
TRANSLATOR_ANTHROPIC_MODEL=claude-3-5-sonnet-latest

# OpenAI 호환 프로바이더 — API 키 + 선택 모델 지정
OPENAI_API_KEY=sk-...
TRANSLATOR_OPENAI_MODEL=gpt-4o-mini
GEMINI_API_KEY=AIza...
TRANSLATOR_GEMINI_MODEL=gemini-2.0-flash
DEEPSEEK_API_KEY=sk-...
OPENROUTER_API_KEY=sk-or-...

# 캐싱
TRANSLATOR_CACHE=true
TRANSLATOR_CACHE_STORE=          # 비우면 기본 스토어 사용
TRANSLATOR_CACHE_TTL=86400       # 초 단위. 비우면 영구 캐시
```

> 배치 번역은 모델에 JSON 객체를 요청해 입력 개수와 순서를 보장하며, 개수가 맞지 않으면 예외를 던집니다.

### LLM 프로바이더 (여러 개 등록)

`deepl`, `google`, `anthropic`, `fallback`이 아닌 이름은 모두 **OpenAI 호환** 엔드포인트로 취급됩니다.
잘 알려진 프로바이더는 base URI가 내장 프리셋으로 자동 해석되므로, `key`와 `model`만 주면 됩니다:

```php
// config/translator.php
'drivers' => [
    'openai'     => ['key' => env('OPENAI_API_KEY'),     'model' => 'gpt-4o-mini'],
    'gemini'     => ['key' => env('GEMINI_API_KEY'),     'model' => 'gemini-2.0-flash'],
    'deepseek'   => ['key' => env('DEEPSEEK_API_KEY'),   'model' => 'deepseek-chat'],
    'openrouter' => ['key' => env('OPENROUTER_API_KEY'), 'model' => 'openai/gpt-4o-mini'],
    'ollama'     => ['model' => 'llama3.2'], // 셀프호스트, 키 불필요
],
```

프리셋은 `openai`, `gemini`, `deepseek`, `groq`, `mistral`, `xai`, `openrouter`, `ollama`를 지원합니다.
Claude는 1st-party **네이티브** 드라이버(`anthropic`)라 마찬가지로 `key`와 `model`을 받습니다:

```php
'anthropic' => ['key' => env('ANTHROPIC_API_KEY'), 'model' => 'claude-3-5-sonnet-latest'],
```

```php
Translator::driver('anthropic')->translate('Hello', 'ko'); // 결과의 ->driver 는 "anthropic"
```

### 커스텀 OpenAI 호환 엔드포인트

프리셋에 없는 엔드포인트(사내 게이트웨이, 프록시, OpenAI chat 스키마를 노출하는 벤더 등)는 드라이버 항목에 **`base_uri`**를 넣으면 됩니다.
서로 다른 키로 여러 개 등록할 수 있습니다.

```php
// config/translator.php
'drivers' => [
    'custom' => [
        'base_uri' => 'https://my-gateway.test/v1',
        'key'      => env('TRANSLATOR_CUSTOM_KEY'),
        'model'    => 'my-model',

        // 'headers' => ['X-Tenant' => 'acme'], // 추가 HTTP 헤더
        // 'options' => ['temperature' => 0.0], // temperature, max_tokens, system_prompt
    ],
],
```

```php
Translator::driver('custom')->translate('Hello', 'ko'); // 결과의 ->driver 는 "custom"
```

## 사용법

### 단건 번역

```php
use Minhyung\LaravelTranslator\Facades\Translator;

$result = Translator::translate('Hello, world!', 'ko');

$result->text;               // "안녕하세요, 여러분!"
$result->detectedSourceLang; // "en"
$result->driver;             // "deepl"
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

### 드라이버 선택

```php
Translator::driver('google')->translate('Hello', 'ko');

// LLM 드라이버 — 호출 단위로 옵션 전달 가능
Translator::driver('openai')->translate('Hello', 'ko', 'en', [
    'temperature'   => 0.0,
    'system_prompt' => 'Translate from {source} into {target}. Keep it formal.',
]);
```

### 의존성 주입

`Translator` 계약(contract)은 기본 드라이버로 바인딩되어 있습니다.

```php
use Minhyung\LaravelTranslator\Contracts\Translator;

public function __construct(private Translator $translator) {}
```

## 캐싱

`translator.cache.enabled`가 켜져 있으면 모든 드라이버가 `CachingTranslator`로 감싸집니다.
동일한 입력(텍스트 · 소스/타깃 언어 · 옵션)은 Laravel 캐시에서 즉시 반환되어 API 호출과 비용을 줄입니다.
배치 번역 시에는 **캐시 미스 항목만** 모아 한 번에 호출합니다.

## Failover

특정 프로바이더가 장애를 일으킬 때 다음 프로바이더로 자동 전환하려면 `fallback` 프로바이더를 사용합니다.
나열한 순서대로 시도하고, 프로바이더가 예외를 던지면 다음으로 넘어갑니다.

```php
// config/translator.php
'default' => 'fallback',

'drivers' => [
    // 여러 프로바이더를 자유롭게 조합
    'anthropic' => ['key' => env('ANTHROPIC_API_KEY'), 'model' => 'claude-3-5-sonnet-latest'],
    'gemini'    => ['key' => env('GEMINI_API_KEY'),    'model' => 'gemini-2.0-flash'],

    'fallback' => [
        'drivers' => ['deepl', 'anthropic', 'gemini'],
    ],
],
```

```php
Translator::translate('Hello', 'ko'); // deepl 실패 시 anthropic → gemini 순으로 시도
```

- 각 자식 드라이버는 **개별적으로 캐싱**되며(`fallback` 자체는 이중 캐시를 피하기 위해 캐싱하지 않음), 전환 시도는 PSR 로거로 `warning` 로깅됩니다.
- 모든 드라이버가 실패하면 `AllTranslationDriversFailedException`이 발생하고, `getErrors()`로 드라이버별 원인 예외를 얻을 수 있습니다.

## 드라이버 확장

새 번역 서비스는 `Contracts\Translator`를 구현하고 매니저에 등록하면 됩니다.

```php
use Minhyung\LaravelTranslator\TranslatorManager;

app(TranslatorManager::class)->extend('papago', function ($app) {
    return new \App\Translation\PapagoTranslator(/* ... */);
});
```

## 테스트

```bash
composer install
vendor/bin/pest
```

## License

MIT
