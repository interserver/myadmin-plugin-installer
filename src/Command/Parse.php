<?php
/**
 * Plugins Management
 * Last Changed: $LastChangedDate: 2017-04-27 04:45:04 -0400 (Thu, 27 Apr 2017) $
 * @author detain
 * @copyright 2017
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\PluginInstaller\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

/**
 * Code Parser Comand
 *
 * Coloring - http://symfony.com/doc/current/console/coloring.html
 * Formatting - http://symfony.com/doc/current/components/console/helpers/formatterhelper.html
 * Table - http://symfony.com/doc/current/components/console/helpers/table.html
 * Style - http://symfony.com/doc/current/console/style.html
 * Process Helper - http://symfony.com/doc/current/components/console/helpers/processhelper.html
 * Progress Bar - http://symfony.com/doc/current/components/console/helpers/progressbar.html
 * Question Helper - http://symfony.com/doc/current/components/console/helpers/questionhelper.html
 *
 */
class Parse extends BaseCommand {
	protected function configure() {
		$this
			->setName('myadmin:parse') // the name of the command (the part after "bin/console")
			->setDescription('Parses PHP DocBlocks') // the short description shown while running "php bin/console list"
			->setHelp('This command parses your PHP Files and getting the PHP DocBlocks for each of the functions, classes, methods, etc..    It Parses meaningful information out from them and generates data on available calls including help  text, arguments, etc..'); // the full command description shown when running the command with the "--help" option
	}

	/** (optional)
	 * This method is executed before the interact() and the execute() methods.
	 * Its main purpose is to initialize variables used in the rest of the command methods.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 */
	protected function initialize(InputInterface $input, OutputInterface $output) {}

	/** (optional)
	 * This method is executed after initialize() and before execute().
	 * Its purpose is to check if some of the options/arguments are missing and interactively
	 * ask the user for those values. This is the last place where you can ask for missing
	 * options/arguments. After this command, missing options/arguments will result in an error.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 */
	protected function interact(InputInterface $input, OutputInterface $output) {}


	/** (required)
	 * This method is executed after interact() and initialize().
	 * It contains the logic you want the command to execute.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$output->writeln([ // outputs multiple lines to the console (adding "\n" at the end of each line)
			'MyAdmin DocBlock Parser',
			'=======================',
			'',
		]);
		$output->writeln('<info>foo</info>'); // green text
		$output->writeln('<comment>foo</comment>'); // yellow text
		$output->writeln('<question>foo</question>'); // black text on a cyan background
		$output->writeln('<error>foo</error>'); // white text on a red background
		$formatter = $this->getHelper('formatter');
		// Section - [SomeSection] Here is some message related to that section
		$formattedLine = $formatter->formatSection('SomeSection', 'Here is some message related to that section');
		$output->writeln($formattedLine);
		// Error Block
		$errorMessages = ['Error!', 'Something went wrong'];
		$formattedBlock = $formatter->formatBlock($errorMessages, 'error');
		$output->writeln($formattedBlock);

		$paths = ['include', 'scripts'];
		$calls = [
			'getFiles'      => ['getHash', 'getSource', 'getNamespaces', 'getIncludes', 'getConstants', 'getFunctions', 'getInterfaces', 'getTraits', 'getPath', 'getDocBlock', 'getName'],
			'getNamespaces' => ['getClasses', 'getConstants', 'getFunctions', 'getInterfaces', 'getTraits', 'getFqsen', 'getName'],
			'getClasses'    => ['isFinal', 'isAbstract', 'getParent', 'getInterfaces', 'getConstants', 'getMethods', 'getProperties', 'getUsedTraits', 'getFqsen', 'getName', 'getDocBlock'],
			'getMethods'    => ['isAbstract', 'isFinal', 'isStatic', 'getVisibility', 'getArguments', 'getFqsen', 'getName', 'getDocBlock'],
			'getProperties' => ['isStatic', 'getDefault', 'getTypes', 'getVisibility', 'getFqsen', 'getName', 'getDocBlock'],
			'getTraits'     => ['getMethods', 'getProperties', 'getUsedTraits', 'getFqsen', 'getName', 'getDocBlock'],
			'getInterfaces' => ['getParents', 'getConstants', 'getMethods', 'getFqsen', 'getName', 'getDocBlock'],
			'getArguments'  => ['isByReference', 'isVariadic', 'getName', 'getTypes', 'getDefault'],
			'getFunctions'  => ['getArguments', 'getFqsen', 'getName', 'getDocBlock'],
			'getConstants'  => ['getValue', 'getFqsen', 'getName', 'getDocBlock'],
		];
		/**
		 * @param $parent
		 * @param $call
		 * @param $calls
		 * @return array
		 */
		function do_call($parent, $call, $calls) {
			echo "Running \$parent->$call();".PHP_EOL;
			$response = $parent->$call();
			if (isset($calls[$call])) {
				$out = [];
				/** @var \phpDocumentor\Reflection\Php\File $file */
				foreach ($response as $idx => $child)
					foreach ($calls[$call] as $childCall)
						/** @var \phpDocumentor\Reflection\Php\File $file */
						$out[$idx] = $childResponse = $child->$childCall();
						echo "Running \$child->$childCall();".PHP_EOL;
				return $out;
			} else
				return $response;
		}

		$projectFactory = \phpDocumentor\Reflection\Php\ProjectFactory::createInstance();
		$files = [];
		$map = [];
		foreach ($paths as $path)
			$files[] = new \phpDocumentor\Reflection\File\LocalFile(__DIR__.'/../../../../../'.$path);
		/** @var Project $project */
		$project = $projectFactory->create('MyProject', $files);
		$map = do_call($project, 'getFiles', $calls);
		file_put_contents(__DIR__.'/../../../../../include/config/parse.serial', serialize($map));
		file_put_contents(__DIR__.'/../../../../../include/config/parse.json', json_encode($map, JSON_PRETTY_PRINT));
		/** @var \phpDocumentor\Reflection\Php\Class_ $class */
		/* foreach ($file->getClasses() as $class)
			echo '- ' . $class->getFqsen() . PHP_EOL;
		} */
		/** @var \phpDocumentor\Reflection\Php\Function_ $function */
		/* foreach ($file->getFunctions() as $function) {
			echo '- ' . $function->getFqsen() . PHP_EOL;
		} */

		/** DocBlock / Reflection Parsing
		 * Reconstituting a docblock - https://github.com/phpDocumentor/ReflectionDocBlock/blob/master/examples/03-reconstituting-a-docblock.php
		 * Adding Your own Tag - https://github.com/phpDocumentor/ReflectionDocBlock/blob/master/examples/04-adding-your-own-tag.php
		 */

		/*
		$class = new ReflectionClass('MyClass');
		$phpdoc = new \phpDocumentor\Reflection\DocBlock($class);

		var_dump($phpdoc->getShortDescription());
		var_dump($phpdoc->getLongDescription()->getContents());
		var_dump($phpdoc->getTags());
		var_dump($phpdoc->hasTag('author'));
		var_dump($phpdoc->hasTag('copyright'));
		// But we can also grab all tags of a specific type, such as `see`
		$seeTags = $docblock->getTagsByName('see');

		$reflector = new ReflectionClass('Example');
		// to get the Class DocBlock
		echo $reflector->getDocComment();
		// to get the Method DocBlock
		$reflector->getMethod('fn')->getDocComment();
		*/
	}
}
