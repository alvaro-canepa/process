<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Process;

/**
 * Process is a thin wrapper around proc_* functions to ease
 * start independent PHP processes.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @api
 */
class Process
{
    const ERR = 'err';
    const OUT = 'out';

    private $commandline;
    private $cwd;
    private $env;
    private $stdin;
    private $timeout;
    private $options;
    private $exitcode;
    private $status;
    private $stdout;
    private $stderr;
    private $enhanceWindowsCompatibility = true;

    /**
     * Exit codes translation table.
     *
     * @var array
     */
    static public $exitCodes = array(
        0 => 'OK',
        1 => 'General error',
        2 => 'Misuse of shell builtins',

        126 => 'Invoked command cannot execute',
        127 => 'Command not found',
        128 => 'Invalid exit argument',

        // signals
        129 => 'Hangup',
        130 => 'Interrupt',
        131 => 'Quit and dump core',
        132 => 'Illegal instruction',
        133 => 'Trace/breakpoint trap',
        134 => 'Process aborted',
        135 => 'Bus error: "access to undefined portion of memory object"',
        136 => 'Floating point exception: "erroneous arithmetic operation"',
        137 => 'Kill (terminate immediately)',
        138 => 'User-defined 1',
        139 => 'Segmentation violation',
        140 => 'User-defined 2',
        141 => 'Write to pipe with no one reading',
        142 => 'Signal raised by alarm',
        143 => 'Termination (request to terminate)',
        // 144 - not defined
        145 => 'Child process terminated, stopped (or continued*)',
        146 => 'Continue if stopped',
        147 => 'Stop executing temporarily',
        148 => 'Terminal stop signal',
        149 => 'Background process attempting to read from tty ("in")',
        150 => 'Background process attempting to write to tty ("out")',
        151 => 'Urgent data available on socket',
        152 => 'CPU time limit exceeded',
        153 => 'File size limit exceeded',
        154 => 'Signal raised by timer counting virtual time: "virtual timer expired"',
        155 => 'Profiling timer expired',
        // 156 - not defined
        157 => 'Pollable event',
        // 158 - not defined
        159 => 'Bad syscall',
    );

    /**
     * Constructor.
     *
     * @param string  $commandline The command line to run
     * @param string  $cwd         The working directory
     * @param array   $env         The environment variables or null to inherit
     * @param string  $stdin       The STDIN content
     * @param integer $timeout     The timeout in seconds
     * @param array   $options     An array of options for proc_open
     *
     * @throws \RuntimeException When proc_open is not installed
     *
     * @api
     */
    public function __construct($commandline, $cwd = null, array $env = null, $stdin = null, $timeout = 60, array $options = array())
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('The Process class relies on proc_open, which is not available on your PHP installation.');
        }

        $this->commandline = $commandline;
        $this->cwd = null === $cwd ? getcwd() : $cwd;
        if (null !== $env) {
            $this->env = array();
            foreach ($env as $key => $value) {
                $this->env[(binary) $key] = (binary) $value;
            }
        } else {
            $this->env = null;
        }
        $this->stdin = $stdin;
        $this->timeout = $timeout;
        $this->options = array_merge(array('suppress_errors' => true, 'binary_pipes' => true), $options);
    }

    /**
     * Runs the process.
     *
     * The callback receives the type of output (out or err) and
     * some bytes from the output in real-time. It allows to have feedback
     * from the independent process during execution.
     *
     * The STDOUT and STDERR are also available after the process is finished
     * via the getOutput() and getErrorOutput() methods.
     *
     * @param Closure|string|array $callback A PHP callback to run whenever there is some
     *                                       output available on STDOUT or STDERR
     *
     * @return integer The exit status code
     *
     * @throws \RuntimeException When process can't be launch or is stopped
     *
     * @api
     */
    public function run($callback = null)
    {
        $this->stdout = '';
        $this->stderr = '';
        $that = $this;
        $out = self::OUT;
        $err = self::ERR;
        $callback = function ($type, $data) use ($that, $callback, $out, $err)
        {
            if ($out == $type) {
                $that->addOutput($data);
            } else {
                $that->addErrorOutput($data);
            }

            if (null !== $callback) {
                call_user_func($callback, $type, $data);
            }
        };

        $descriptors = array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w'));

        $commandline = $this->commandline;

        if (defined('PHP_WINDOWS_VERSION_BUILD') && $this->enhanceWindowsCompatibility) {
            $commandline = 'cmd /V:ON /E:ON /C "'.$commandline.'"';
            if (!isset($this->options['bypass_shell'])) {
                $this->options['bypass_shell'] = true;
            }
        }

        $process = proc_open($commandline, $descriptors, $pipes, $this->cwd, $this->env, $this->options);

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to launch a new process.');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        if (null === $this->stdin) {
            fclose($pipes[0]);
            $writePipes = null;
        } else {
            $writePipes = array($pipes[0]);
            $stdinLen = strlen($this->stdin);
            $stdinOffset = 0;
        }
        unset($pipes[0]);

        while ($pipes || $writePipes) {
            $r = $pipes;
            $w = $writePipes;
            $e = null;

            $n = @stream_select($r, $w, $e, $this->timeout);

            if (false === $n) {
                break;
            } elseif ($n === 0) {
                proc_terminate($process);

                throw new \RuntimeException('The process timed out.');
            }

            if ($w) {
                $written = fwrite($writePipes[0], (binary) substr($this->stdin, $stdinOffset), 8192);
                if (false !== $written) {
                    $stdinOffset += $written;
                }
                if ($stdinOffset >= $stdinLen) {
                    fclose($writePipes[0]);
                    $writePipes = null;
                }
            }

            foreach ($r as $pipe) {
                $type = array_search($pipe, $pipes);
                $data = fread($pipe, 8192);
                if (strlen($data) > 0) {
                    call_user_func($callback, $type == 1 ? $out : $err, $data);
                }
                if (false === $data || feof($pipe)) {
                    fclose($pipe);
                    unset($pipes[$type]);
                }
            }
        }

        $this->status = proc_get_status($process);

        $time = 0;
        while (1 == $this->status['running'] && $time < 1000000) {
            $time += 1000;
            usleep(1000);
            $this->status = proc_get_status($process);
        }

        $exitcode = proc_close($process);

        if ($this->status['signaled']) {
            throw new \RuntimeException(sprintf('The process stopped because of a "%s" signal.', $this->status['stopsig']));
        }

        return $this->exitcode = $this->status['running'] ? $exitcode : $this->status['exitcode'];
    }

    /**
     * Returns the output of the process (STDOUT).
     *
     * This only returns the output if you have not supplied a callback
     * to the run() method.
     *
     * @return string The process output
     *
     * @api
     */
    public function getOutput()
    {
        return $this->stdout;
    }

    /**
     * Returns the error output of the process (STDERR).
     *
     * This only returns the error output if you have not supplied a callback
     * to the run() method.
     *
     * @return string The process error output
     *
     * @api
     */
    public function getErrorOutput()
    {
        return $this->stderr;
    }

    /**
     * Returns the exit code returned by the process.
     *
     * @return integer The exit status code
     *
     * @api
     */
    public function getExitCode()
    {
        return $this->exitcode;
    }

    /**
     * Returns a string representation for the exit code returned by the process.
     *
     * This method relies on the Unix exit code status standardization
     * and might not be relevant for other operating systems.
     *
     * @return string A string representation for the exit status code
     *
     * @see http://tldp.org/LDP/abs/html/exitcodes.html
     * @see http://en.wikipedia.org/wiki/Unix_signal
     */
    public function getExitCodeText()
    {
        return isset(self::$exitCodes[$this->exitcode]) ? self::$exitCodes[$this->exitcode] : 'Unknown error';
    }

    /**
     * Checks if the process ended successfully.
     *
     * @return Boolean true if the process ended successfully, false otherwise
     *
     * @api
     */
    public function isSuccessful()
    {
        return 0 == $this->exitcode;
    }

    /**
     * Returns true if the child process has been terminated by an uncaught signal.
     *
     * It always returns false on Windows.
     *
     * @return Boolean
     *
     * @api
     */
    public function hasBeenSignaled()
    {
        return $this->status['signaled'];
    }

    /**
     * Returns the number of the signal that caused the child process to terminate its execution.
     *
     * It is only meaningful if hasBeenSignaled() returns true.
     *
     * @return integer
     *
     * @api
     */
    public function getTermSignal()
    {
        return $this->status['termsig'];
    }

    /**
     * Returns true if the child process has been stopped by a signal.
     *
     * It always returns false on Windows.
     *
     * @return Boolean
     *
     * @api
     */
    public function hasBeenStopped()
    {
        return $this->status['stopped'];
    }

    /**
     * Returns the number of the signal that caused the child process to stop its execution
     *
     * It is only meaningful if hasBeenStopped() returns true.
     *
     * @return integer
     *
     * @api
     */
    public function getStopSignal()
    {
        return $this->status['stopsig'];
    }

    public function addOutput($line)
    {
        $this->stdout .= $line;
    }

    public function addErrorOutput($line)
    {
        $this->stderr .= $line;
    }

    public function getCommandLine()
    {
        return $this->commandline;
    }

    public function setCommandLine($commandline)
    {
        $this->commandline = $commandline;
    }

    public function getTimeout()
    {
        return $this->timeout;
    }

    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    public function getWorkingDirectory()
    {
        return $this->cwd;
    }

    public function setWorkingDirectory($cwd)
    {
        $this->cwd = $cwd;
    }

    public function getEnv()
    {
        return $this->env;
    }

    public function setEnv(array $env)
    {
        $this->env = $env;
    }

    public function getStdin()
    {
        return $this->stdin;
    }

    public function setStdin($stdin)
    {
        $this->stdin = $stdin;
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function setOptions(array $options)
    {
        $this->options = $options;
    }

    public function getEnhanceWindowsCompatibility()
    {
        return $this->enhanceWindowsCompatibility;
    }

    public function setEnhanceWindowsCompatibility($enhance)
    {
        $this->enhanceWindowsCompatibility = (Boolean) $enhance;
    }

}
