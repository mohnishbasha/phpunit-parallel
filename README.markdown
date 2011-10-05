PHPUnit_Parallel
=============================

[PBWorks](http://pbworks.com/) and [SauceLabs](http://saucelabs.com) parallelism for [PHPUnit](http://www.phpunit.de), because it's been over four years since PHPUnit promised to support parallelism natively

THIS IS NOT READY YET

You can install PHPUnit_Parallel via the [Sauce Labs PEAR channel](http://saucelabs.github.com/pear). Run this from your command line:

    pear channel-discover saucelabs.github.com/pear
    pear channel-update saucelabs.github.com/pear
    pear install saucelabs/PHPUnit_Parallel

   run-parallel is a tool for running our unit test suite in parallel.
   It speeds things up by about 20x.
   Unlike phpunit itself, we don't have access to test results as they
   happen, only once the entire test suite completes.
   The output of this tool while it is running consists of the following:
     < Indicates that a test suite has started
     > Indicates that a test suite has finished
     . Indicates that a test within a test suite passed
     E Indicates that a test within a test suite encountered an error
     F Indicates that a test within a test suite failed
     S Indicates that a test within a test suite was skipped
   With the exception of < and >, this is identical to phpunit.

   Command line arguments:
   -c  How many suites to run simultaneously. Default is 5.
   -s  Run a particular suite or group of suites. Separate multiple suites
       with a comma, but no spaces (i.e. -s foo,bar,baz).
   -g  Run a specified group of tests as specified by the @group tag in the test.
   -t  Outputs a listing of how long each suite took to run.
   -v  When used with -t, includes the times of the individual tests
       within suites.
   -x  Generate XML log and store it at the given path (i.e. -x log.xml)
   -f Only run failing/incomplete tests
