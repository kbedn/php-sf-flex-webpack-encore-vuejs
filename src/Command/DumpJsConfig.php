<?php
namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;

class DumpJsConfig extends ContainerAwareCommand
{
    const ARG_HOST = 'host';

    const ARG_PORT = 'port';

    const ARG_QUASAR_STYLE = 'quasarStyle';

    /**
     * @var \Twig_Environment
     */
    protected $twig;

    /**
     * @var RouterInterface
     */
    protected $router;

    /**
     * @var string
     */
    protected $csrfTokenParameter;

    /**
     * @var string
     */
    protected $apiPlatformPrefix;

    /**
     * @var string
     */
    protected $loginUsernamePath;

    /**
     * @var string
     */
    protected $loginPasswordPath;

    /**
     * @var string
     */
    protected $tokenJwtBearer;

    /**
     * @var string
     */
    protected $rootDir;

    /**
     * DumpJsConfig constructor.
     * @param string $csrfTokenParameter
     * @param string $apiPlatformPrefix
     * @param string $loginUsernamePath
     * @param string $loginPasswordPath
     * @param string $tokenJwtBearer
     * @param \Twig_Environment $twig
     * @param RouterInterface $router
     */
    public function __construct(
        string $csrfTokenParameter,
        string $apiPlatformPrefix,
        string $loginUsernamePath,
        string $loginPasswordPath,
        string $tokenJwtBearer,
        \Twig_Environment $twig,
        RouterInterface $router)
    {
        parent::__construct();

        $this->twig = $twig;
        $this->router = $router;
        $this->csrfTokenParameter = $csrfTokenParameter;
        $this->apiPlatformPrefix = $apiPlatformPrefix;
        $this->loginUsernamePath = $loginUsernamePath;
        $this->loginPasswordPath = $loginPasswordPath;
        $this->tokenJwtBearer = $tokenJwtBearer;
    }

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName('app:dump-js-config')
            ->setDescription('Create the config.js file.')
            ->setHelp('Dump symfony configuration into a config.js file available from assets/js/*')
            ->addArgument(self::ARG_HOST, InputArgument::OPTIONAL, 'The full hostname of the web-server.', 'localhost')
            ->addArgument(self::ARG_PORT, InputArgument::OPTIONAL, 'The port for the web-server.', '80')
            ->addArgument(self::ARG_QUASAR_STYLE, InputArgument::OPTIONAL, 'The style for quasar framework: mat or ios.', 'mat');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $env = $this->getEnv();
        $host = $input->getArgument(self::ARG_HOST);
        $port = $input->getArgument(self::ARG_PORT);
        $quasarStyle = $input->getArgument(self::ARG_QUASAR_STYLE);

        if (!$this->validateInputs($output, $port, $quasarStyle)) {
            return;
        }

        $apiPlatform = $this->loadApiPlatformConfig($output);
        $this->displayJsConfigArguments($output, $apiPlatform, $host, $port, $quasarStyle);

        $content = $this->render($env, $host, $port, $quasarStyle, $apiPlatform);
        $this->displayJsConfigOutput($output, $content);

        $this->writeJsConfigFile($input, $output, $content);
    }

    /**
     * @param OutputInterface $output
     * @return array
     */
    protected function loadApiPlatformConfig(OutputInterface $output): array
    {
        try {
            $missingKeys = [];
            $mandatoryKeys = ['items_per_page', 'client_items_per_page', 'items_per_page_parameter_name', 'maximum_items_per_page', ];
            $configDir = $this->getContainer()->get('kernel')->getRootDir() . '/../config/';
            $values = Yaml::parseFile($configDir . 'packages/api_platform.yaml');
            $config = $values['api_platform'];

            if (!($config['collection'] && $config['collection']['pagination'])) {
                throw new \Exception('Missing pagination section in api_platform.yaml');
            }

            foreach ($mandatoryKeys as $key) {
                if (array_key_exists($key, $config['collection']['pagination'])) {
                    continue;
                }

                $missingKeys[] = $key;
            }

            if (count($missingKeys)) {
                throw new \Exception(sprintf('those keys are mandatory for the frontend configuration: %s', join(', ', $missingKeys)));
            }

            return $config['collection']['pagination'];
        } catch (\Exception $e) {
            $output->writeln(sprintf('api_platform.yaml error: "%s"', $e->getMessage()));
        }

        return [];
    }

    /**
     * @return string
     */
    protected function getEnv(): string
    {
        $systemEnv = getenv();
        $env = 'dev'; // default
        if (is_array($systemEnv)
            && array_key_exists('APP_ENV', $systemEnv)) {
            $env = $systemEnv['APP_ENV'];
        }

        return $env;
    }

    /**
     * @param OutputInterface $output
     * @param $port
     * @param $quasarStyle
     * @return bool
     */
    protected function validateInputs(OutputInterface $output, $port, $quasarStyle): bool
    {
        $validator = Validation::createValidator();
        $violations = [];

        $violations[self::ARG_PORT] = $validator->validate($port, [
            new Assert\Type(['type' => 'numeric', ]),
        ]);

        $violations[self::ARG_QUASAR_STYLE] = $validator->validate($quasarStyle, [
            new Assert\Choice(['choices' => ['mat', 'ios'], ]),
        ]);

        if (0 !== count($violations[self::ARG_PORT]) && 0 !== count($violations[self::ARG_QUASAR_STYLE])) {
            $output->writeln([
                'Params errors',
                '=======================', ]);
            foreach ($violations as $paramName => $violation) {
                foreach ($violation as $v) {
                    $output->writeln(ucfirst($paramName) . ': ' . $v->getMessage());
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @param OutputInterface $output
     * @param $apiPlatform
     * @param $host
     * @param $port
     * @param $quasarStyle
     */
    protected function displayJsConfigArguments(OutputInterface $output, $apiPlatform, $host, $port, $quasarStyle): void
    {
        $apiPlatformOutput = function() use ($apiPlatform) {
            $output = [];
            foreach ($apiPlatform as $key => $value) {
                $output[] = $key . ': ' . $value;
            }

            return join(', ', $output);
        };
        $output->writeln([
            'Js config file creation',
            '=======================',
            'arguments:',
            'host:port = ' . $host . ':' . $port,
            'quasarStyle = ' . $quasarStyle,
            'apiPlatform = ' . $apiPlatformOutput(),
        ]);
    }

    /**
     * @param $env
     * @param $host
     * @param $port
     * @param $quasarStyle
     * @param $apiPlatform
     * @return string
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    protected function render($env, $host, $port, $quasarStyle, $apiPlatform): string
    {
        $content = $this->twig->render('command/config.js.twig', [
            'env' => $env,
            'host' => trim($host),
            'port' => trim($port),
            'csrfTokenParameter' => $this->csrfTokenParameter,
            'apiPlatformPrefix' => $this->apiPlatformPrefix,
            'loginUsernamePath' => $this->loginUsernamePath,
            'loginPasswordPath' => $this->loginPasswordPath,
            'tokenJwtBearer' => $this->tokenJwtBearer,
            'uriLoginJson' => $this->router->generate('demo_login_json_check'),
            'uriLoginJwt' => $this->router->generate('app_loginjwt_newtoken'),
            'uriIsLoggedInJson' => $this->router->generate('demo_secured_page_json_is_logged_in'),
            'uriIsLoggedInJwt' => $this->router->generate('demo_secured_page_jwt_is_logged_in'),
            'quasarStyle' => $quasarStyle,
            'apiPlatform' => $apiPlatform,
        ]);

        return $content;
    }

    /**
     * @param OutputInterface $output
     * @param $content
     */
    protected function displayJsConfigOutput(OutputInterface $output, $content): void
    {
        $output->writeln([
            'File content',
            '============',
            $content,
        ]);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param $content
     */
    protected function writeJsConfigFile(InputInterface $input, OutputInterface $output, $content): void
    {
        $helper = $this->getHelper('question');
        $projectDir = $this->getContainer()->get('kernel')->getRootDir() . '/..';
        $configFilepath = '/assets/js/lib/config.js';
        $filepath = $projectDir . $configFilepath;

        if (file_exists($filepath)) {
            $question = new ConfirmationQuestion($configFilepath . ' file already exists, confirm it`s replacement (y or n, default n) ?', false);
            $response = $helper->ask($input, $output, $question);
            if (!$response) {
                $output->writeln([
                    $response,
                    'Process stopped',
                    'New file not saved',
                ]);

                return;
            }
        }

        $res = file_put_contents($filepath, $content);

        if (!$res) {
            $output->writeln([
                'Creation file failed',
            ]);
        } else {
            $output->writeln([
                'File created at ' . $configFilepath,
            ]);
        }
    }
}
