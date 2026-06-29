# laravel-translator

여러 번역 서비스(DeepL, Google Cloud Translation 등)를 **하나의 통일된 API**로 사용하는 Laravel 패키지입니다.
Laravel 표준 Manager/Driver 패턴으로 설계되어 드라이버를 쉽게 추가/교체할 수 있고, 번역 결과 캐싱을 기본 제공합니다.

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
TRANSLATOR_DRIVER=deepl          # 기본 드라이버: deepl | google

# DeepL
DEEPL_AUTH_KEY=xxxxxxxx:fx

# Google Cloud Translation
GOOGLE_CLOUD_PROJECT=my-gcp-project
GOOGLE_TRANSLATE_LOCATION=global
GOOGLE_APPLICATION_CREDENTIALS=/path/to/service-account.json

# 캐싱
TRANSLATOR_CACHE=true
TRANSLATOR_CACHE_STORE=          # 비우면 기본 스토어 사용
TRANSLATOR_CACHE_TTL=86400       # 초 단위. 비우면 영구 캐시
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
