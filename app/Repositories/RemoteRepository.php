<?php

namespace App\Repositories;

use Illuminate\Support\Arr;
use Symfony\Component\Process\Process;

class RemoteRepository
{
    /**
     * The sockets path.
     *
     * @var string
     */
    protected $socketsPath;

    /**
     * The server.
     *
     * @var \Laravel\Forge\Resources\Server|null
     */
    protected $server = null;

    /**
     * The server resolver.
     *
     * @var callable|null
     */
    protected $serverResolver = null;

    /**
     * Creates a new repository instance.
     *
     * @param  string  $socketsPath
     * @return void
     */
    public function __construct($socketsPath)
    {
        $this->socketsPath = $socketsPath;
    }

    /**
     * Execute a command against the shell, and displays the output.
     *
     * @param  string|null  $command
     * @return int
     */
    public function passthru($command = null)
    {
        $this->ensureSshIsConfigured();

        passthru($this->ssh('"'.$command.'"'), $exitCode);

        return (int) $exitCode;
    }

    /**
     * Tails the given file, and runs the given callback on each output.
     *
     * @param  array|string  $files
     * @param  callable  $callback
     * @param  array  $options
     * @return int
     */
    public function tail($files, $callback, $options = [])
    {
        $this->ensureSshIsConfigured();

        $files = Arr::wrap($files);

        $command = collect(explode(' ', $this->ssh()))->merge([
            'tail',
            '-n',
            '500',
            ...$options,
            '$(ls -1td '.implode(' ', $files).' 2>/dev/null | head -n1)',
        ])->values()->all();

        $process = tap(new Process($command), function ($process) {
            $process->setTimeout(null);

            $process->start();
        });

        $callback($process);

        return $process->getExitCode() == 255
            ? 0 // Control + C
            : $process->getExitCode();
    }

    /**
     * Execute a command against the shell, and returns the output.
     *
     * @param  string  $command
     * @return array
     */
    public function exec($command)
    {
        $this->ensureSshIsConfigured();

        exec($this->ssh($command), $output, $exitCode);

        return [(int) $exitCode, $output];
    }

    /**
     * Sets the current server.
     *
     * @param  callable  $resolver
     * @return void
     */
    public function resolveServerUsing($resolver)
    {
        $this->serverResolver = $resolver;
    }

    /**
     * Ensure user can connect to current server.
     *
     * @return void
     */
    public function ensureSshIsConfigured()
    {
        once(function () {
            abort_if(is_null($this->serverResolver), 1, 'Current server unresolvable.');

            if (is_null($this->server)) {
                $this->server = call_user_func($this->serverResolver);
            }

            exec($this->ssh('-t exit 0'), $_, $exitCode);

            abort_if($exitCode > 0, 1, 'Unable to connect to remote server. Maybe run [ssh:configure] to configure an SSH Key?');
        });
    }

    /**
     * Returns the "ssh" sheel command to be run.
     *
     * @param  string  $command|null
     * @return string
     */
    protected function ssh($command = null)
    {
        $options = collect([
            'ConnectTimeout' => 5,
            'ControlMaster' => 'auto',
            'ControlPersist' => 100,
            'ControlPath' => $this->socketsPath.'/%h-%p-%r',
            'LogLevel' => 'QUIET',
        ])->map(function ($value, $option) {
            return "-o $option=$value";
        })->values()->implode(' ');

        return trim(sprintf(
            'ssh %s -t forge@%s %s',
            $options,
            $this->server->ipAddress,
            $command,
        ));
    }
}
