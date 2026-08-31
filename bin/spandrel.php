#!/usr/bin/env php
<?php

declare(strict_types=1);

use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Console\AnalyseCommand;
use Spandrel\Spandrel\Console\ClearCacheCommand;
use Spandrel\Spandrel\Console\DebugLayersCommand;
use Spandrel\Spandrel\Console\DebugRulesetCommand;
use Spandrel\Spandrel\Console\InitCommand;
use Spandrel\Spandrel\Console\LintCommand;
use Spandrel\Spandrel\Console\RulesetLoader;
use Spandrel\Spandrel\Console\SourceGraphBuilder;
use Spandrel\Spandrel\Loader\Loader;
use Spandrel\Spandrel\Parser\Parser;
use Spandrel\Spandrel\RuleEngine\RuleEngine;
use Spandrel\Spandrel\Ruleset\RulesetParser;
use Spandrel\Spandrel\Version\Version;
use Symfony\Component\Console\Application;

require dirname(__DIR__).'/vendor/autoload.php';

// Single composition root: every command's collaborators are constructed
// once here and passed in via constructor, rather than each command
// reaching for `new` on its own dependencies.
$configLoader = new ConfigLoader();
$rulesetLoader = new RulesetLoader(new RulesetParser());
$sourceGraphBuilder = new SourceGraphBuilder(new Loader(), new Parser());
$ruleEngine = new RuleEngine();

$application = new Application('Spandrel', Version::current());
$application->addCommand(new DebugLayersCommand($configLoader, $rulesetLoader, $sourceGraphBuilder));
$application->addCommand(new LintCommand($configLoader, $rulesetLoader, $sourceGraphBuilder));
$application->addCommand(new DebugRulesetCommand($configLoader, $rulesetLoader, $sourceGraphBuilder));
$application->addCommand(new AnalyseCommand($configLoader, $rulesetLoader, $sourceGraphBuilder, $ruleEngine));
$application->addCommand(new ClearCacheCommand($configLoader));
$application->addCommand(new InitCommand());
$application->run();
