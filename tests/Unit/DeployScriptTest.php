<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
 * Блокировка production-стека и make deploy-status — docker/deploy/deploy.sh (docs/deployment.md,
 * «Одна команда за раз»). Скрипт выполняет оболочка тестового контейнера; git, make, docker, date и
 * flock — подставные из tests/Fixtures/DeployScript: они пишут каждый вызов в журнал вместе с тем,
 * занята ли блокировка в этот момент, а по воротам (STUB_GATE) ждут, пока тест их не откроет.
 *
 * make в тестовом контейнере нет, поэтому то, как Makefile подключает цели к скрипту, последний тест
 * проверяет по тексту Makefile.
 */
beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/deploy-script-'.bin2hex(random_bytes(6));
    $this->project = $this->sandbox.'/project';
    $this->state = $this->sandbox.'/state';
    $this->background = [];

    mkdir($this->project, 0777, true);
    mkdir($this->sandbox.'/bin');
    mkdir($this->state);

    foreach (['docker', 'git', 'make', 'date', 'flock'] as $stub) {
        copy(getcwd().'/tests/Fixtures/DeployScript/'.$stub, $this->sandbox.'/bin/'.$stub);
        chmod($this->sandbox.'/bin/'.$stub, 0755);
    }

    foreach (['calls', 'invocations', 'flock'] as $file) {
        file_put_contents($this->state.'/'.$file, '');
    }

    $this->script = getcwd().'/docker/deploy/deploy.sh';
    $this->realFlock = (new ExecutableFinder)->find('flock');

    /**
     * Скрипт с аргументами («lock -- docker compose down») в отдельной сессии: сигнал группе
     * процессов доходит до скрипта и всего, что он запустил, как Ctrl+C.
     *
     * @param  array<string, string>  $environment
     */
    $this->process = function (string $command, array $environment = [], ?array $commandLine = null): Process {
        $process = new Process(
            $commandLine ?? ['setsid', 'sh', $this->script, ...explode(' ', $command)],
            $this->project,
            [
                'PATH' => $this->sandbox.'/bin:'.getenv('PATH'),
                'STUB_STATE' => $this->state,
                'STUB_SCRIPT' => $this->script,
                'STUB_REAL_FLOCK' => $this->realFlock,
                ...$environment,
            ],
        );
        $process->setTimeout(60);

        return $process;
    };

    /**
     * @param  array<string, string>  $environment
     */
    $this->run = function (string $command, array $environment = []): Process {
        $process = ($this->process)($command, $environment);
        $process->run();

        return $process;
    };

    /**
     * Запускает скрипт и возвращается, когда он ждёт у ворот подставной команды: docker у docker,
     * pull у git pull, lock-info у date строки о держателе блокировки.
     *
     * @param  array<string, string>  $environment
     */
    $this->startUntilGate = function (string $command, string $gate, array $environment = []): Process {
        $process = ($this->process)($command, ['STUB_GATE' => $gate, ...$environment]);
        $process->start();
        $this->background[] = $process->getPid();
        $deadline = microtime(true) + 30;

        while (! file_exists($this->state.'/gate-reached')) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                $process->stop(0);

                throw new RuntimeException("«{$command}» не дошёл до ворот:\n".$process->getErrorOutput().$process->getOutput());
            }

            usleep(20000);
        }

        return $process;
    };

    // Свободна ли блокировка — так, как это видит flock: её можно взять сейчас же.
    $this->lockIsFree = function (): bool {
        $lock = fopen($this->project.'/.deploy/lock', 'r');
        $free = flock($lock, LOCK_EX | LOCK_NB);
        fclose($lock);

        return $free;
    };

    $this->waitUntilLockIsFree = function (): void {
        $deadline = microtime(true) + 10;

        while (! ($this->lockIsFree)()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('блокировка всё ещё занята');
            }

            usleep(20000);
        }
    };

    $this->waitUntilEnded = function (Process $process): void {
        while ($process->isRunning()) {
            usleep(20000);
        }
    };

    $this->waitUntil = function (Closure $condition, ?Process $process = null): void {
        $deadline = microtime(true) + 10;

        while (! $condition()) {
            if (microtime(true) > $deadline || ($process !== null && ! $process->isRunning())) {
                throw new RuntimeException('не дождались: '.($process?->getErrorOutput() ?? ''));
            }

            usleep(20000);
        }
    };

    /**
     * Запускает в своей сессии команду, которая берёт блокировку сама (flock(1), не скрипт), и
     * возвращается, когда блокировка занята. flock без -n: пока lockIsFree проверяет блокировку,
     * команда ждёт, а не отказывает.
     *
     * @param  list<string>  $commandLine
     */
    $this->startHolding = function (array $commandLine): Process {
        $process = ($this->process)('', [], ['setsid', ...$commandLine]);
        $process->start();
        $this->background[] = $process->getPid();
        ($this->waitUntil)(fn (): bool => ! ($this->lockIsFree)(), $process);

        return $process;
    };

    // Копия программы, которую можно только запускать: её процесс не показывает этому пользователю
    // свои дескрипторы, как процесс другого пользователя.
    $this->hidden = function (string $name): string {
        $path = $this->sandbox.'/hidden/'.$name;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path));
        }

        copy(realpath((new ExecutableFinder)->find($name)), $path);
        chmod($path, 0111);

        return $path;
    };

    /** @return list<string> */
    $this->lines = fn (string $file): array => array_values(array_filter(explode("\n", file_get_contents($this->state.'/'.$file))));

    $this->lockLine = fn (): string => (string) @file_get_contents($this->project.'/.deploy/lock');

    $this->busy = fn (string $command, int $pid): string => '/^error: the production stack is busy: '.preg_quote($command, '/')." \\(pid {$pid}\\) since \\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d [+-]\\d{4}\\. Nothing was done; run the command again when that one has finished\\n$/";

    // Ветка, которую приносит git pull, на три коммита впереди.
    $this->gitBehind = [
        'STUB_GIT_COMMIT' => '1a2b3c4 2026-10-23 10:00 +0500 Деплой: пример',
        'STUB_GIT_BRANCH' => 'master',
        'STUB_GIT_REMOTE' => 'origin',
        'STUB_GIT_MERGE' => 'refs/heads/master',
        'STUB_GIT_BEHIND' => '3',
        'STUB_GIT_AHEAD' => '0',
    ];
});

afterEach(function () {
    // Запущенный скрипт работает в своей сессии: так заканчиваются он и всё, что он оставил.
    foreach ($this->background as $pid) {
        @posix_kill(-$pid, 9);
    }

    (new Process(['rm', '-rf', $this->sandbox]))->run();
});

test('команда под блокировкой держит её до конца, записывает себя в файл и отпускает блокировку после', function () {
    $process = ($this->run)('lock -- docker compose up -d', ['DEPLOY_COMMAND' => 'make up']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(($this->lines)('calls'))->toBe(['docker compose up -d (lock held, DEPLOY_LOCK_HELD=1)'])
        ->and(($this->lockLine)())->toMatch('/^make up \\(pid \\d+\\) since \\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d [+-]\\d{4}\\n$/')
        ->and(($this->lockIsFree)())->toBeTrue();

    // Код завершения команды — код всей цели; упавшая команда блокировку тоже отпускает.
    $failed = ($this->run)('lock -- docker compose down', ['DEPLOY_COMMAND' => 'make down', 'STUB_DOCKER_EXIT' => '3']);

    expect($failed->getExitCode())->toBe(3)
        ->and(($this->lockIsFree)())->toBeTrue();
});

test('make deploy берёт блокировку до git pull и держит её до последнего шага скачанного Makefile', function () {
    $process = ($this->run)('deploy', ['DEPLOY_COMMAND' => 'make deploy']);

    // Шаги после git pull выполняет новый make: он читает уже скачанный Makefile.
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toStartWith("git pull\n")
        ->and(($this->lines)('calls'))->toBe([
            'git pull (lock held, DEPLOY_LOCK_HELD=1)',
            'make --no-print-directory deploy-steps (lock held, DEPLOY_LOCK_HELD=1)',
            'docker compose up -d --build --remove-orphans (lock held, DEPLOY_LOCK_HELD=1)',
        ])
        ->and(($this->lockLine)())->toMatch('/^make deploy \(pid \d+\) since /')
        ->and(($this->lockIsFree)())->toBeTrue();
});

test('если git pull не удался, шаги деплоя не запускаются, а блокировка свободна', function () {
    $process = ($this->run)('deploy', ['DEPLOY_COMMAND' => 'make deploy', 'STUB_GIT_PULL_EXIT' => '1']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('error: git pull failed; nothing was deployed')
        ->and(($this->lines)('calls'))->toBe(['git pull (lock held, DEPLOY_LOCK_HELD=1)'])
        ->and(($this->lockIsFree)())->toBeTrue();
});

test('пока идёт деплой, любая команда, меняющая стек, сразу отказывает и называет деплой, а deploy-status работает', function () {
    $deploy = ($this->startUntilGate)('deploy', 'docker', ['DEPLOY_COMMAND' => 'make deploy']);
    $calls = ($this->lines)('calls');

    foreach ([
        'make build' => 'lock -- docker compose up -d --build --remove-orphans',
        'make up' => 'lock -- docker compose up -d --remove-orphans',
        'make down' => 'lock -- docker compose down',
        'make down-volumes' => 'lock -- docker compose down -v',
        'make restart' => 'lock -- make --no-print-directory down up',
        'make deploy' => 'deploy',
    ] as $name => $command) {
        $startedAt = microtime(true);
        $refused = ($this->run)($command, ['DEPLOY_COMMAND' => $name]);

        // Не ждёт: деплой у ворот стоит, пока тест их не откроет.
        expect($refused->getExitCode())->toBe(1, "«{$name}» не получил отказа:\n".$refused->getErrorOutput().$refused->getOutput())
            ->and(microtime(true) - $startedAt)->toBeLessThan(5)
            ->and($refused->getErrorOutput())->toMatch(($this->busy)('make deploy', $deploy->getPid()));
    }

    // Отказавшие команды не запустили ни git, ни make, ни docker и не переписали строку деплоя.
    expect(($this->lines)('calls'))->toBe($calls)
        ->and(($this->lockLine)())->toStartWith("make deploy (pid {$deploy->getPid()}) since ")
        ->and($deploy->isRunning())->toBeTrue();

    // deploy-status блокировку не берёт: ни одного вызова flock.
    $flockCalls = ($this->lines)('flock');
    $status = ($this->run)('status', $this->gitBehind);

    expect($status->getExitCode())->toBe(0, $status->getErrorOutput())
        ->and($status->getOutput())->toMatch("/^lock: held by make deploy \\(pid {$deploy->getPid()}\\) since [^\\n]+$/m")
        ->and(($this->lines)('flock'))->toBe($flockCalls);

    touch($this->state.'/gate-open');
    $deploy->wait();

    expect($deploy->getExitCode())->toBe(0, $deploy->getErrorOutput())
        ->and(($this->lockIsFree)())->toBeTrue();

    // Когда деплой закончился, следующая команда берёт блокировку.
    expect(($this->run)('lock -- docker compose up -d', ['DEPLOY_COMMAND' => 'make up'])->getExitCode())->toBe(0);
});

test('Ctrl+C, закрытый терминал и kill -9 всей команды сразу освобождают блокировку', function (int $signal) {
    $cut = ($this->startUntilGate)('deploy', 'pull', ['DEPLOY_COMMAND' => 'make deploy']);
    $pid = $cut->getPid();

    posix_kill(-$pid, $signal);
    ($this->waitUntilEnded)($cut);
    ($this->waitUntilLockIsFree)();

    // Строка прерванного деплоя остаётся в файле, но блокировку не держит.
    expect($cut->isSuccessful())->toBeFalse()
        ->and(($this->lockLine)())->toStartWith("make deploy (pid {$pid}) since ");

    $status = ($this->run)('status', $this->gitBehind);

    expect($status->getOutput())->toContain("lock: free, last taken by make deploy (pid {$pid}) since ");

    $next = ($this->run)('lock -- docker compose up -d', ['DEPLOY_COMMAND' => 'make up']);

    expect($next->getExitCode())->toBe(0, $next->getErrorOutput())
        ->and(($this->lockLine)())->toMatch('/^make up \(pid \d+\) since /');
})->with([
    'Ctrl+C (SIGINT)' => SIGINT,
    'закрытый терминал (SIGHUP)' => SIGHUP,
    'kill -9 всей команды (SIGKILL), без шанса прибраться' => SIGKILL,
]);

test('если убить только саму команду, запущенное ею держит блокировку до своего конца и названо в отказе', function (string $command, string $name, string $gate, string $holder) {
    $cut = ($this->startUntilGate)($command, $gate, ['DEPLOY_COMMAND' => $name]);
    $pid = $cut->getPid();

    // Только сам процесс команды: запущенное им дорабатывает и меняет стек, поэтому держит блокировку.
    posix_kill($pid, SIGKILL);
    ($this->waitUntilEnded)($cut);

    $calls = ($this->lines)('calls');
    $refused = ($this->run)('lock -- docker compose down', ['DEPLOY_COMMAND' => 'make down']);

    expect($refused->getExitCode())->toBe(1, $refused->getErrorOutput().$refused->getOutput())
        ->and($refused->getErrorOutput())->toStartWith('error: the production stack is busy: the lock is held by pid ')
        ->and($refused->getErrorOutput())->toMatch("/held by [^;]*pid \\d+ \\([^)]*{$holder}\\)/")
        ->and($refused->getErrorOutput())->toContain("; .deploy/lock names {$name} (pid {$pid}) since ")
        ->and($refused->getErrorOutput())->toContain(', which no longer holds it: the holder is a command it started that still runs, or a command that has not written its own line. Nothing was done; run the command again when the holder has finished')
        ->and($refused->getErrorOutput())->not->toContain("busy: {$name} (pid {$pid})")
        ->and(($this->lines)('calls'))->toBe($calls);

    // deploy-status не выдаёт блокировку за свободную и называет тех же держателей.
    $status = ($this->run)('status', $this->gitBehind);

    expect($status->getOutput())->toMatch("/^lock: held by [^;]*pid \\d+ \\([^)]*{$holder}\\)[^\\n]*which no longer holds it/m");

    // Когда запущенное закончилось, блокировка свободна и следующая команда выполняется.
    touch($this->state.'/gate-open');
    ($this->waitUntilLockIsFree)();
    $next = ($this->run)('lock -- docker compose up -d', ['DEPLOY_COMMAND' => 'make up']);

    expect($next->getExitCode())->toBe(0, $next->getErrorOutput().$next->getOutput());
})->with([
    'make deploy во время git pull' => ['deploy', 'make deploy', 'pull', 'git pull'],
    'make restart во время docker compose down' => ['lock -- make --no-print-directory down up', 'make restart', 'docker', 'docker compose down'],
]);

test('отказ называет настоящего держателя, если тот взял блокировку, но ещё не записал свою строку', function () {
    // Строка давно закончившейся команды.
    mkdir($this->project.'/.deploy');
    file_put_contents($this->project.'/.deploy/lock', "make up (pid 999999) since 2026-10-20 10:00:00 +0000\n");
    $holder = ($this->startUntilGate)('lock -- docker compose up -d', 'lock-info', ['DEPLOY_COMMAND' => 'make build']);
    $pid = $holder->getPid();

    $refused = ($this->run)('lock -- docker compose down', ['DEPLOY_COMMAND' => 'make down']);

    expect($refused->getExitCode())->toBe(1, $refused->getErrorOutput().$refused->getOutput())
        ->and($refused->getErrorOutput())->toStartWith('error: the production stack is busy: the lock is held by ')
        ->and($refused->getErrorOutput())->toContain("pid {$pid} (sh {$this->script} lock -- docker compose up -d)")
        ->and($refused->getErrorOutput())->toContain('; .deploy/lock names make up (pid 999999) since 2026-10-20 10:00:00 +0000, which no longer holds it')
        ->and($refused->getErrorOutput())->not->toContain('busy: make up');

    touch($this->state.'/gate-open');
    $holder->wait();

    expect($holder->getExitCode())->toBe(0, $holder->getErrorOutput())
        ->and(($this->lockLine)())->toStartWith("make build (pid {$pid}) since ");
});

test('flock(1), взятый вручную через дескриптор на чтение, — держатель: его называют deploy-status и отказ, а процесс, который файл только читает, держателем не считается', function () {
    mkdir($this->project.'/.deploy');
    file_put_contents($this->project.'/.deploy/lock', "make up (pid 999999) since 2026-10-20 10:00:00 +0000\n");

    // Файл открыт, но блокировки на нём нет: sleep получил его на вход от оболочки.
    $reader = ($this->process)('', [], ['setsid', 'sh', '-c', 'exec sleep 30 < .deploy/lock']);
    $reader->start();
    $this->background[] = $reader->getPid();
    ($this->waitUntil)(fn (): bool => file_get_contents("/proc/{$reader->getPid()}/cmdline") === "sleep\x0030\x00", $reader);

    expect(($this->run)('status', $this->gitBehind)->getOutput())
        ->toContain("lock: free, last taken by make up (pid 999999) since 2026-10-20 10:00:00 +0000\n");

    // flock(1) открывает файл на чтение и держит блокировку вместе с запущенной командой.
    $holder = ($this->startHolding)([$this->realFlock, '.deploy/lock', 'sleep', '30']);
    $holderName = "pid {$holder->getPid()} ({$this->realFlock} .deploy/lock sleep 30)";
    $status = ($this->run)('status', $this->gitBehind)->getOutput();

    expect($status)->toContain("lock: held by {$holderName}")
        ->and($status)->toMatch('/^lock: held by [^\n]*pid \d+ \(sleep 30\); \.deploy\/lock names make up \(pid 999999\) since 2026-10-20 10:00:00 \+0000, which no longer holds it/m')
        ->and($status)->not->toContain("pid {$reader->getPid()} ");

    $refused = ($this->run)('lock -- docker compose down', ['DEPLOY_COMMAND' => 'make down']);

    expect($refused->getExitCode())->toBe(1)
        ->and($refused->getErrorOutput())->toStartWith('error: the production stack is busy: the lock is held by ')
        ->and($refused->getErrorOutput())->toContain($holderName)
        ->and(($this->lines)('calls'))->toBe([]);

    posix_kill(-$holder->getPid(), SIGKILL);
    ($this->waitUntilLockIsFree)();

    expect(($this->run)('status', $this->gitBehind)->getOutput())
        ->toContain("lock: free, last taken by make up (pid 999999) since 2026-10-20 10:00:00 +0000\n");
});

test('если процессы держателя этому пользователю не видны, deploy-status не говорит «free»: занято по /proc/locks, а где ядро блокировку не показывает — unknown', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('root видит процессы всех пользователей');
    }

    mkdir($this->project.'/.deploy');
    file_put_contents($this->project.'/.deploy/lock', "make up (pid 999999) since 2026-10-20 10:00:00 +0000\n");
    $flock = ($this->hidden)('flock');
    $sleep = ($this->hidden)('sleep');
    $refusal = "error: the production stack is busy: another command holds .deploy/lock, its processes are not visible to this user; the last command recorded there: make up (pid 999999) since 2026-10-20 10:00:00 +0000. Nothing was done; run the command again when that one has finished\n";

    // Тот, кто взял блокировку, работает: ядро показывает её в /proc/locks, кто бы ни смотрел.
    $holder = ($this->startHolding)([$flock, '.deploy/lock', $sleep, '30']);

    expect(($this->run)('status', $this->gitBehind)->getOutput())
        ->toContain("lock: held by a command whose processes are not visible to this user; the last command recorded in .deploy/lock: make up (pid 999999) since 2026-10-20 10:00:00 +0000\n")
        ->and(($this->run)('lock -- docker compose down', ['DEPLOY_COMMAND' => 'make down'])->getErrorOutput())->toBe($refusal);

    posix_kill(-$holder->getPid(), SIGKILL);
    ($this->waitUntilLockIsFree)();

    // Закончившиеся невидимые процессы (зомби) свободной блокировке не мешают.
    expect(($this->run)('status', $this->gitBehind)->getOutput())
        ->toContain("lock: free, last taken by make up (pid 999999) since 2026-10-20 10:00:00 +0000\n");

    // Взявший блокировку flock(1) закончился, держит только невидимый процесс. В начальном пространстве
    // pid ядро показывает такую блокировку в /proc/locks, в контейнере — нет: тогда unknown.
    $holder = ($this->startHolding)(['sh', '-c', 'exec 9>> .deploy/lock && "$0" 9 && exec "$1" 30', $this->realFlock, $sleep]);
    $initialPidNamespace = readlink('/proc/self/ns/pid') === 'pid:[4026531836]';

    expect(($this->run)('status', $this->gitBehind)->getOutput())->toContain($initialPidNamespace
        ? "lock: held by a command whose processes are not visible to this user; the last command recorded in .deploy/lock: make up (pid 999999) since 2026-10-20 10:00:00 +0000\n"
        : "lock: unknown, no holder is visible to this user, but some processes are not, and /proc/locks here does not list every lock; last taken by make up (pid 999999) since 2026-10-20 10:00:00 +0000\n")
        ->and(($this->run)('lock -- docker compose down', ['DEPLOY_COMMAND' => 'make down'])->getErrorOutput())->toBe($refusal)
        ->and(($this->lines)('calls'))->toBe([]);
});

test('make restart держит одну блокировку на down и up: вложенные команды не отказывают себе', function () {
    $process = ($this->run)('lock -- make --no-print-directory down up', ['DEPLOY_COMMAND' => 'make restart']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(($this->lines)('calls'))->toBe([
            'make --no-print-directory down up (lock held, DEPLOY_LOCK_HELD=1)',
            'docker compose down (lock held, DEPLOY_LOCK_HELD=1)',
            'docker compose up -d --remove-orphans (lock held, DEPLOY_LOCK_HELD=1)',
        ])
        // Вложенные make down и make up не переписывают строку: держит make restart.
        ->and(($this->lockLine)())->toMatch('/^make restart \(pid \d+\) since /')
        ->and(($this->lockIsFree)())->toBeTrue();
});

test('без блокировки make deploy-steps и команда с DEPLOY_LOCK_HELD=1 отказывают и ничего не делают', function () {
    $steps = ($this->run)('held', ['DEPLOY_COMMAND' => 'make deploy-steps']);

    expect($steps->getExitCode())->toBe(1)
        ->and($steps->getErrorOutput())->toBe("error: make deploy-steps runs only inside make deploy, under its lock of the production stack: run make deploy\n");

    // Переменная без дескриптора блокировки ничего не даёт.
    expect(($this->run)('held', ['DEPLOY_LOCK_HELD' => '1'])->getExitCode())->toBe(1);

    $nested = ($this->run)('lock -- docker compose down', ['DEPLOY_LOCK_HELD' => '1']);

    expect($nested->getExitCode())->toBe(1)
        ->and($nested->getErrorOutput())->toBe("error: DEPLOY_LOCK_HELD=1, but descriptor 9 holds no lock on .deploy/lock. Nothing was done\n");

    // Как и открытый на файл блокировки дескриптор 9, пока блокировку держит другая команда.
    $holder = ($this->startUntilGate)('lock -- docker compose up -d', 'docker', ['DEPLOY_COMMAND' => 'make up']);
    $intruder = ($this->process)('', ['DEPLOY_LOCK_HELD' => '1'], [
        'setsid', 'sh', '-c', 'exec 9>> .deploy/lock; exec sh "$0" lock -- docker compose down', $this->script,
    ]);
    $intruder->run();

    expect($intruder->getExitCode())->toBe(1)
        ->and($intruder->getErrorOutput())->toContain('descriptor 9 holds no lock on .deploy/lock')
        ->and(($this->lines)('calls'))->toBe(['docker compose up -d (lock held, DEPLOY_LOCK_HELD=1)']);

    touch($this->state.'/gate-open');
    $holder->wait();
});

test('если файл блокировки не создать, команда не выполняется', function () {
    // На месте каталога .deploy — файл.
    touch($this->project.'/.deploy');

    $process = ($this->run)('lock -- docker compose up -d', ['DEPLOY_COMMAND' => 'make up']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toBe("error: cannot create .deploy/lock. Nothing was done\n")
        ->and(($this->lines)('calls'))->toBe([]);
});

test('make deploy-status показывает коммит, отставание от ветки git pull, локальные правки и блокировку', function () {
    $status = ($this->run)('status', $this->gitBehind);

    expect($status->getExitCode())->toBe(0, $status->getErrorOutput())
        ->and($status->getOutput())->toBe(implode("\n", [
            'commit: 1a2b3c4 2026-10-23 10:00 +0500 Деплой: пример',
            'upstream: origin/master, commits behind: 3, ahead: 0 (fetched just now)',
            'local changes: none',
            'lock: free',
        ])."\n");

    // Ветка скачивается в свою ссылку: ни origin/master, ни FETCH_HEAD, которые пишет git pull идущего
    // деплоя, git status не трогает индекс; блокировку и её файл deploy-status не трогает.
    $invocations = ($this->lines)('invocations');

    expect($invocations)->toContain('git -c gc.auto=0 -c maintenance.auto=false fetch --quiet --no-write-fetch-head --refmap= origin +refs/heads/master:refs/deploy-status/upstream [GIT_OPTIONAL_LOCKS=0]')
        ->and(array_filter($invocations, fn (string $call): bool => ! str_ends_with($call, '[GIT_OPTIONAL_LOCKS=0]')))->toBe([])
        ->and(array_filter($invocations, fn (string $call): bool => str_contains($call, ' pull')))->toBe([])
        ->and(($this->lines)('flock'))->toBe([])
        ->and(file_exists($this->project.'/.deploy'))->toBeFalse();
});

test('make deploy-status без GitHub считает отставание по последнему git pull и не зависает', function (array $environment, string $reason) {
    $startedAt = microtime(true);
    $status = ($this->run)('status', [...$this->gitBehind, 'STUB_GIT_BEHIND_LAST' => '1', ...$environment]);

    expect($status->getExitCode())->toBe(0, $status->getErrorOutput())
        ->and(microtime(true) - $startedAt)->toBeLessThan(8)
        ->and($status->getOutput())->toContain("upstream: origin/master, commits behind: 1, ahead: 0 (as of the last fetch; fetching it now failed: {$reason})\n");
})->with([
    'GitHub отказал' => [
        ['STUB_GIT_FETCH_EXIT' => '128', 'STUB_GIT_FETCH_ERROR' => 'fatal: Could not read from remote repository.\n\nPlease make sure you have the correct access rights\nand the repository exists.'],
        'fatal: Could not read from remote repository.',
    ],
    'GitHub не ответил' => [
        ['STUB_GIT_FETCH_SLEEP' => '30', 'DEPLOY_FETCH_TIMEOUT' => '1'],
        'no answer in 1s',
    ],
]);

test('make deploy-status: ветка без upstream, локальные правки и свободная блокировка с прошлой записью', function () {
    mkdir($this->project.'/.deploy');
    file_put_contents($this->project.'/.deploy/lock', "make up (pid 999999) since 2026-10-20 10:00:00 +0000\n");

    $status = ($this->run)('status', [
        ...$this->gitBehind,
        'STUB_GIT_BRANCH' => '',
        'STUB_GIT_CHANGES' => ' M Makefile\n M docker-compose.yml\n',
    ]);

    expect($status->getExitCode())->toBe(0, $status->getErrorOutput())
        ->and($status->getOutput())->toBe(implode("\n", [
            'commit: 1a2b3c4 2026-10-23 10:00 +0500 Деплой: пример',
            'upstream: none for a detached HEAD, git pull has nothing to bring',
            'local changes: 2 tracked files differ from the commit, git pull may refuse to run',
            'lock: free, last taken by make up (pid 999999) since 2026-10-20 10:00:00 +0000',
        ])."\n")
        ->and(array_filter(($this->lines)('invocations'), fn (string $call): bool => str_contains($call, ' fetch ')))->toBe([]);
});

test('в шаблоне laravel-docker-template тот же скрипт', function () {
    expect(file_get_contents(getcwd().'/laravel-docker-template/docker/deploy/deploy.sh'))
        ->toBe(file_get_contents(getcwd().'/docker/deploy/deploy.sh'));
});

test('Makefile: на проде цели, меняющие стек, идут через блокировку, локально — как раньше, без неё', function (string $directory) {
    $makefile = file_get_contents(getcwd().'/'.$directory.'Makefile');
    $recipe = fn (string $target): string => preg_match('/^'.preg_quote($target, '/').':[^\n]*\n((?:\t[^\n]*\n)*)/m', $makefile, $match) ? $match[1] : '';

    // Блокировка — только при APP_ENV=production.
    expect($makefile)->toContain("DEPLOY_SH := sh docker/deploy/deploy.sh\n")
        ->toContain("PROD_LOCK = \$(if \$(IS_PRODUCTION),DEPLOY_COMMAND='make \$@' \$(DEPLOY_SH) lock --)\n");

    foreach ([
        'build' => 'up -d --build --remove-orphans',
        'up' => 'up -d --remove-orphans',
        'down' => 'down --remove-orphans',
        'down-volumes' => 'down -v --remove-orphans',
    ] as $target => $compose) {
        expect($recipe($target))->toBe("\t\$(LOAD_ENV) \$(PROD_LOCK) \$(ENV_COMPOSE) {$compose}\n");
    }

    expect($makefile)->toContain("ifeq (\$(IS_PRODUCTION),)\nrestart: down up\nelse\nrestart: guard-owner\n\t\$(PROD_LOCK) \$(MAKE) --no-print-directory down up\nendif\n")
        ->and($recipe('deploy'))->toEndWith("\t@DEPLOY_COMMAND='make deploy' \$(DEPLOY_SH) deploy\n")
        ->and($recipe('deploy'))->not->toContain('git pull')
        ->and($recipe('deploy-steps'))->toStartWith("\t@DEPLOY_COMMAND='make deploy-steps' \$(DEPLOY_SH) held\n")
        ->and($recipe('deploy-status'))->toStartWith("\t@\$(DEPLOY_SH) status\n")
        ->and($recipe('deploy-status'))->not->toContain('lock')
        ->and(file_get_contents(getcwd().'/'.$directory.'.dockerignore'))->toContain("\n.deploy\n");
})->with([
    'проект' => '',
    'шаблон' => 'laravel-docker-template/',
]);

test('файл блокировки не попадает в git: git pull его не заменит и не удалит', function () {
    expect(file_get_contents(getcwd().'/.gitignore'))->toContain("\n/.deploy/\n");
});
