#!/usr/bin/env php
<?php

  //  @fatal_errors off

  /**
   *  run-parallel is a tool for running our unit test suite in parallel.
   *  It speeds things up by about 20x.
   *  Unlike phpunit itself, we don't have access to test results as they
   *  happen, only once the entire test suite completes.
   *  The output of this tool while it is running consists of the following:
   *    < Indicates that a test suite has started
   *    > Indicates that a test suite has finished
   *    . Indicates that a test within a test suite passed
   *    E Indicates that a test within a test suite encountered an error
   *    F Indicates that a test within a test suite failed
   *    S Indicates that a test within a test suite was skipped
   *  With the exception of < and >, this is identical to phpunit.
   *
   *  Command line arguments:
   *  -c  How many suites to run simultaneously. Default is 5.
   *  -s  Run a particular suite or group of suites. Separate multiple suites
   *      with a comma, but no spaces (i.e. -s foo,bar,baz).
   *  -g  Run a specified group of tests as specified by the @group tag in the test.
   *  -t  Outputs a listing of how long each suite took to run.
   *  -v  When used with -t, includes the times of the individual tests
   *      within suites.
   *  -x  Generate XML log and store it at the given path (i.e. -x log.xml)
   */

  $opt = getopt('c:tvfs:x:g:');
  $show_times = isset($opt['t']);
  $verbose = isset($opt['v']);
  $suites = isset($opt['s']) ? explode(',', $opt['s']) : false;
  $xml_log = isset($opt['x']) ? $opt['x'] : false;
  $group = isset($opt['g']) ? $opt['g'] : false;
  $max_running_suites = isset($opt['c']) ? intval($opt['c']) : 5;

  define('kMaxRunningSuites', $max_running_suites);
  define('kSlowSuiteThreshold', 1.0);

  echo "\nOMGUnit 0.2.1 by Mark <3: ";

  if(strpos(getcwd(), 'selenium') !== false) {
    //  Functional tests
    echo "This is a functional test\n";
    define('kTestsDirectory', getcwd() . '/tests/');
    define('kCommand', 'phpunit --log-json %s singleTestRunner.php -t %s');
  } else {
    //  Unit tests
    echo "This is a unit test\n";
    define('kTestsDirectory', getcwd());
    define('kCommand', 'phpunit --log-json %s %s');
  }

  //If there are no suites specified, get a list of all tests in the /tests dir. Filtering by group if that's specified.
  if(!$suites) {
    //  Enumerate files to run
    $suites = array();
    if($group){
      $grep_command = "grep -lr \"@group $group\" . | grep -v svn | grep -v UnderDev | cut -d/ -f2";
      $process = popen( $grep_command, "r" );
        while($hit = fgets($process, 4096)) {
          $suites[] = trim($hit);
        }
      pclose($process);
    } else {
      $d = dir(kTestsDirectory);
      while (($entry = $d->read()) !== false) {
        if ($entry != basename(__FILE__) && substr($entry, -4) == '.php') {
          $suites[] = $entry;
        }
      }
      $d->close();
    }
  }
  sort($suites);

  //  Run tests
  $t0 = time();
  $results = run_suites_parallel($suites);
  $t1 = time();
  $t_total = $t1 - $t0;

  //  Show test times
  if($show_times) {
    echo "\n\nSuite times:\n";
    arsort($results['suite_times']);

    $total_cpu_time = 0;
    foreach($results['suite_times'] as $name => $time) {
      $total_cpu_time += $time;
      if($verbose) {
        //  Detailed output
        echo "$name ($time)\n";
        $suite_details = $results['suite_time_details'][$name];
        if ($suite_details === null) {
          continue;
        }
        arsort($suite_details);
        foreach($suite_details as $name => $time) {
          //  Highlight slow tests
          if($time > kSlowSuiteThreshold) {
            echo '* ';
          } else {
            echo '  ';
          }
          echo "$time\t$name\n";
        }
        echo "\n";
      } else {
        //  Summary output
        echo "$time\t$name\n";
      }
    }

    $time_ratio = round($total_cpu_time / $t_total, 2);
    echo "Total CPU time:\t{$total_cpu_time} seconds\n";
    echo "OMG time ratio:\t{$time_ratio}\n";
  }

  //  Display results
  echo "\n\nTime: {$t_total} seconds\n\n";
  if($results['error']) {
    display_output($results['error'], 'error');
  }
  if($results['fail']) {
    if($results['error']) {
      echo "\n--\n";
    }
    display_output($results['fail'], 'failure');
  }

  //  Display final summary
  if($results['error'] || $results['fail']) {
    $suite_count = $results['total'];
    $fail_count = count($results['fail']);
    $skip_count = $results['skip'];
    echo "FAILURES!\n";
    echo "Tests: {$suite_count}, Errors: {$fail_count}, Skipped: {$skip_count}\n";
    $exit_code = 1;
  } else {
    echo "OK ({$results['total']} tests)\n";
    $exit_code = 0;
  }

  exit($exit_code);

  function run_suites_parallel($suites) {
    $pending_suites = array();
    $running_suites = array();
    $completed_suites = array();

    foreach($suites as $suite) {
      $output_file = tempnam('/tmp/omgunit/', sha1(__FILE__) . '-' . $suite . '.json');
      $command = sprintf(kCommand, $output_file, $suite);
      echo ' ' . $command . "\n";
      $pending_suites[] = array(
        'handle' => $suite['handle'],
        'command' => $command,
        'suite' => $suite,
        'output_file' => $output_file,
        'output' => '',
      );
    }

    $summary = array(
      'total' => 0,
      'pass' => 0,
      'skip' => 0,
      'error' => array(),
      'fail' => array(),
      'total_time' => 0,
      'times' => array(),
    );

    $proc_open_descriptors = array(
       0 => array('pipe', 'r'),
       1 => array('pipe', 'w'),
       2 => array('pipe', 'w'),
    );

    //  Main run loop -- runs until no tests remain
    while(count($running_suites) || count($pending_suites)) {
      $count = 0;

      usleep(10000); // 10ms

      while(count($pending_suites) && count($running_suites) < kMaxRunningSuites) {
        foreach($pending_suites as $index => $suite) {
          $suite['pipes'] = array();
          $suite['handle'] = proc_open($suite['command'], $proc_open_descriptors, $suite['pipes']);
          $suite['start_time'] = microtime(true);
          $running_suites[] = $suite;
          unset($pending_suites[$index]);
          echo '<';
          break;
        }
      }

      foreach($running_suites as $index => $suite) {
        $suite_name = $suite['suite'];
        $ph = $suite['handle'];
        $new_data = false;
        $status = proc_get_status($ph);
        if($status['running']) {
          //  Don't do anything while the suite is still running
          continue;
        }

        //  Get test results
        //  Mangle phpunit's output so json_decode will accept it
        $json = file_get_contents($suite['output_file']);
        $json = '[' . str_replace('}{', '}, {', $json) . ']';
        $suite['result'] = json_decode($json, true);

        //  Parse results
        $suite_time = 0;
        foreach($suite['result'] as $info) {
          if($info['event'] != 'test') {
            continue;
          }
          $test_name = $info['test'];
          $summary['total']++;
          $suite_time += $info['time'];
          $summary['suite_time_details'][$suite_name][$test_name] = $info['time'];

          if($info['status'] == 'pass') {
            $summary['pass']++;
            echo '.';
          } else if ($info['status'] == 'fail') {
            $summary['fail'][] = $info;
            echo 'F';
          } else if ($info['status'] == 'error') {
            //  Might just be a skipped test
            if($info['message'] == 'Skipped Test') {
              $summary['skip']++;
              echo 'S';
            } else {
              $summary['error'][] = $info;
              echo 'E';
            }
          }
        }
        $summary['total_time'] += $suite_time;
        $summary['suite_times'][$suite_name] = $suite_time;

        //  Tidy up
        $suite['rv'] = proc_close($ph);
        unlink($suite['output_file']);
        unset($suite['handle']);

        //  Mark test as completed
        $completed_suites[] = $suite;
        unset($running_suites[$index]);
        echo '>';
      }
    }

    return $summary;
  }

  function display_output($results, $word) {
    if(count($results) == 1) {
      echo "There was 1 {$word}:\n\n";
    } else {
      echo "There were " . count($results) . " {$word}s:\n\n";
    }

    foreach($results as $index => $result){
      echo ++$index . ") {$result['suite']}\n";
      echo "{$result['message']}\n";
      foreach($result['trace'] as $trace) {
        echo "{$trace['file']}:{$trace['line']}\n";
      }
      echo "\n";
    }
  }

