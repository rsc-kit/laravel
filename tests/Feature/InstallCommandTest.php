<?php

use Illuminate\Support\Facades\File;

/**
 * The PHP half of the installer.
 *
 * The JavaScript half is `rsc-kit init`'s and is tested where it lives; what
 * is pinned here is the part that touches files someone else wrote. An
 * installer earns its place by being safe to run twice, so most of these are
 * about what it does NOT do: not regenerate a secret the renderer is already
 * configured with, not overwrite a published config, not put a usable value in
 * a committed file.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/rsc-install-'.bin2hex(random_bytes(6));

    File::makeDirectory($this->base.'/config', recursive: true);

    $this->app->setBasePath($this->base);
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

it('generates a secret into .env', function () {
    File::put($this->base.'/.env', "APP_NAME=Laravel\nAPP_ENV=local\n");

    $this->artisan('rsc:install --skip-js')->assertSuccessful();

    $env = File::get($this->base.'/.env');

    expect($env)->toContain('APP_NAME=Laravel');
    expect($env)->toMatch('/RSC_HOST_CALL_SECRET="[A-Za-z0-9+\/]{43}="/');
});

it('never changes a secret that is already there', function () {
    // The renderer is configured with the same value. Regenerating it turns
    // every host call into a 401, which reads as the application refusing its
    // own data rather than as an installer having run twice.
    File::put($this->base.'/.env', "RSC_HOST_CALL_SECRET=\"already-set\"\n");

    $this->artisan('rsc:install --skip-js')->assertSuccessful();

    expect(File::get($this->base.'/.env'))->toBe("RSC_HOST_CALL_SECRET=\"already-set\"\n");
});

it('leaves an empty assignment to be filled rather than treating it as set', function () {
    File::put($this->base.'/.env', "RSC_HOST_CALL_SECRET=\n");

    $this->artisan('rsc:install --skip-js')->assertSuccessful();

    expect(File::get($this->base.'/.env'))->toMatch('/RSC_HOST_CALL_SECRET="[A-Za-z0-9+\/]{43}="/');
});

it('names the secret in .env.example, with no value', function () {
    // .env.example is committed. A secret in a repository is not a secret, and
    // a placeholder that looks usable is worse than none — someone ships it.
    File::put($this->base.'/.env', "APP_NAME=Laravel\n");
    File::put($this->base.'/.env.example', "APP_NAME=Laravel\n");

    $this->artisan('rsc:install --skip-js')->assertSuccessful();

    $example = File::get($this->base.'/.env.example');

    expect($example)->toContain('RSC_HOST_CALL_SECRET=');
    expect($example)->not->toContain(trim(explode('"', File::get($this->base.'/.env'))[1] ?? 'x'));
});

it('says so rather than failing when there is no .env', function () {
    $this->artisan('rsc:install --skip-js')
        ->expectsOutputToContain('RSC_HOST_CALL_SECRET')
        ->assertSuccessful();
});

it('publishes the config', function () {
    // Asserted through what it reports rather than the file on disk: the
    // provider registers its publish target during boot, which happened before
    // this test moved the base path, so the copy lands in the skeleton.
    // Idempotence is the half with teeth, and that one is below.
    File::put($this->base.'/.env', "APP_NAME=Laravel\n");

    $this->artisan('rsc:install --skip-js')
        ->expectsOutputToContain('published')
        ->assertSuccessful();
});

it('leaves a published config alone', function () {
    File::put($this->base.'/.env', "APP_NAME=Laravel\n");
    File::put($this->base.'/config/rsc.php', '<?php return ["mine" => true];');

    $this->artisan('rsc:install --skip-js')->assertSuccessful();

    expect(File::get($this->base.'/config/rsc.php'))->toContain('"mine" => true');
});

it('prints the command for the JavaScript half rather than doing it in PHP', function () {
    // The templates it writes belong to the engine and change with it. A PHP
    // copy of them would be a second implementation drifting from the build
    // that has to read them, so this command runs the generator and never
    // reimplements it.
    File::put($this->base.'/.env', "APP_NAME=Laravel\n");

    $this->artisan('rsc:install --skip-js')
        ->expectsOutputToContain('rsc-kit init --host=laravel')
        ->assertSuccessful();
});
