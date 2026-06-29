# laravel-translator

여러 번역 서비스(DeepL, Google Cloud Translation, LLM 등)를 **하나의 통일된 API**로 사용하는 Laravel 패키지입니다.
Laravel 표준 Manager/Driver 패턴으로 설계되어 드라이버를 쉽게 추가/교체할 수 있고, 번역 결과 캐싱을 기본 제공합니다.

지원 드라이버: **DeepL**, **Google Cloud Translation**, **LLM** ([Prism](https://prismphp.com) 기반 — OpenAI/Anthropic/Gemini 등).

## 요구 사항

- PHP `^8.3`
- Laravel 12 / 13 (`illuminate/support: ^12.0|^13.0`)

> Google 드라이버는 REST 트랜스포트를 사용하므로 `ext-grpc` PECL 확장이 **필요 없습니다.**

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
TRANSLATOR_PROVIDER=deepl        # 기본 프로바이더: deepl | google | llm

# DeepL
DEEPL_AUTH_KEY=xxxxxxxx:fx

# Google Cloud Translation
GOOGLE_CLOUD_PROJECT=my-gcp-project
GOOGLE_TRANSLATE_LOCATION=global
GOOGLE_APPLICATION_CREDENTIALS=/path/to/service-account.json

# LLM (Prism)
TRANSLATOR_LLM_PROVIDER=openai
TRANSLATOR_LLM_MODEL=gpt-4o-mini

# 캐싱
TRANSLATOR_CACHE=true
TRANSLATOR_CACHE_STORE=          # 비우면 기본 스토어 사용
TRANSLATOR_CACHE_TTL=86400       # 초 단위. 비우면 영구 캐시
```

> **LLM 드라이버**는 [Prism](https://prismphp.com)을 사용합니다. 프로바이더 API 키 등은 Prism 설정(`config/prism.php`)에서 관리합니다.
> 배치 번역은 structured output으로 입력 개수와 순서를 보장하며, 개수가 맞지 않으면 예외를 던집니다.

### 이름 있는 프로바이더 (여러 개 등록)

`providers` 배열에 `driver` 타입을 지정한 항목을 추가하면, 그 이름을 그대로 프로바이더로 쓸 수 있습니다.
여러 LLM 프로바이더(또는 같은 드라이버의 여러 계정)를 등록해 failover 체인에 넣을 때 유용합니다.

```php
// config/translator.php
'providers' => [
    'llm'    => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],

    'claude' => ['driver' => 'llm', 'provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest'],
    'gemini' => ['driver' => 'llm', 'provider' => 'gemini',    'model' => 'gemini-2.0-flash'],
],
```

```php
Translator::provider('claude')->translate('Hello', 'ko'); // 결과의 ->driver 는 "claude"
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

### 프로바이더 선택

```php
Translator::provider('google')->translate('Hello', 'ko');

// LLM 프로바이더 — 호출 단위로 옵션 전달 가능
Translator::provider('llm')->translate('Hello', 'ko', 'en', [
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

'providers' => [
    // 이름 있는 프로바이더를 자유롭게 조합 (예: 여러 LLM 프로바이더)
    'claude' => ['driver' => 'llm', 'provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest'],
    'gemini' => ['driver' => 'llm', 'provider' => 'gemini',    'model' => 'gemini-2.0-flash'],

    'fallback' => [
        'providers' => ['deepl', 'claude', 'gemini'],
    ],
],
```

```php
Translator::translate('Hello', 'ko'); // deepl 실패 시 claude → gemini 순으로 시도
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
