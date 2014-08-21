<?php
/**
 * Created by IntelliJ IDEA.
 * User: Nikolay Chervyakov 
 * Date: 19.08.2014
 * Time: 17:31
 */


namespace App\Rest;


use App\Core\Request;
use App\Core\Response;
use App\Exception\HttpException;
use App\Model\User;
use App\Pixie;
use App\Rest\Events\PreActionEvent;
use PHPixie\Auth\Login\Password;


/**
 * The Core of the RESTful service.
 * @package App\Rest
 */
class RestService
{
    /**
     * @var Pixie
     */
    protected $pixie;

    /**
     * @var Request
     */
    protected $request;

    protected $config;

    protected $excludedModels = [];

    /**
     * @var Response
     */
    protected $response;

    public function __construct(Pixie $pixie)
    {
        $this->pixie = $pixie;

        $this->config = $this->pixie->config->get('rest');
        if (is_array($this->config['excluded_models'])) {
            $this->excludeModels($this->config['excluded_models']);
        }

        // Hang REST event listeners
        $pixie->dispatcher->addListener(Events::PRE_PROCESS_ACTION, [$this, 'authenticationListener'], 100);
        $pixie->dispatcher->addListener(Events::PRE_PROCESS_ACTION, [$this, 'checkAllowedMethodsListener'], 10);
    }

    /**
     * Handles the rest request (if route is rest).
     * @param Request $request
     * @param array $cookie
     * @return Response|\PHPixie\Response
     */
    public function handleRequest(Request $request, $cookie = [])
    {
        $this->request = $request;
        $this->response = $this->pixie->response();

        try {
            $this->doHandleRequest($cookie);

        } catch (\Exception $e) {
            $this->response = $this->handleException($e);
        }

        return $this->response;
    }

    /**
     * Does the real handling of the request.
     * @param array $cookie
     */
    protected function doHandleRequest($cookie = [])
    {
        $request = $this->request;
        //$this->request->method = 'PATCH';
        $request->adjustRequestContentType();

        $pixie = $this->pixie;
        $pixie->cookie->set_cookie_data($cookie);
        $controllerName = implode('', array_map('ucfirst', preg_split('/_/', $request->param('controller'))));
        $controller = Controller::createController($controllerName, $request, $pixie);

        // Run all necessary filters
        $this->pixie->dispatcher->dispatch(Events::PRE_PROCESS_ACTION, new PreActionEvent($request, $controller));

        $action = strtolower($request->method);
        if (!($controller instanceof NoneController)) {
            if (!$request->param('id') && $this->request->method == 'GET') {
                $action .= '_collection';
            }

            if ($request->param('id') && $request->param('property')) {
                $action .= '_'.$request->param('property');
            }
        }

        $data = [];
        if ($request->method == 'POST') {
            $data = $request->post();
        } else if (in_array($request->method, ['PUT', 'PATCH'])) {
            $data = $request->put();
        }
        $params = ['data' => $data];

        $controller->run($action, $params);
        $this->response = $controller->response;
    }

    /**
     * Formats exceptions as
     * @param \Exception $e
     * @return \PHPixie\Response
     */
    protected function handleException(\Exception $e)
    {
        $controller = new ErrorController($this->pixie);
        $controller->request = $this->request;
        $controller->setError($e);
        $controller->run('show');
        return $controller->response;
    }

    /**
     * Checks that user is authenticated.
     */
    public function authenticationListener(PreActionEvent $event)
    {
        $headers = getallheaders();

        if (!$headers['Authorization'] || strpos($headers['Authorization'], 'Basic ') !== 0) {
            $this->askForCredentials();
        }

        $parts = preg_split('/\s+/', $headers['Authorization'], 2, PREG_SPLIT_NO_EMPTY);
        $credentials = explode(':', base64_decode($parts[1]));
        $username = $credentials[0];
        $password = $credentials[1];

        if (!$username) {
            $this->askForCredentials();
        }

        /** @var User $user */
        $user = $this->pixie->orm->get('User')->where('username', $username)->find();

        if(!$user->loaded()) {
            $this->askForCredentials();
        }

        /** @var Password $provider */
        $provider = $this->pixie->auth->provider('password');
        $logged = $provider->login($username, $password);

        if ($logged) {
            $event->getController()->setUser($user);
            return;
        }

        $this->askForCredentials();
    }

    /**
     * Check that user uses only allowed methods.
     * @param PreActionEvent $event
     * @throws \App\Exception\HttpException
     */
    public function checkAllowedMethodsListener(PreActionEvent $event)
    {
        $method = strtoupper($event->getRequest()->method);

        if (!in_array($method, $event->getController()->allowedMethods())
            || (!$event->getRequest()->param('id') && !in_array($method, ['GET', 'HEAD', 'OPTIONS', 'POST']))
        ) {
            throw new HttpException('Method Not Allowed', 405);
        }
    }

    private function askForCredentials()
    {
        header('WWW-Authenticate: Basic realm="Provide your credentials."');
        header('HTTP/1.1 401 Unauthorized', true, 401);
        exit;
    }

    /**
     * Exclude some model from being restful accessible (e.g. BaseModel and so on).
     * @param string $modelName
     */
    public function excludeModel($modelName)
    {
        if (!in_array($modelName, $this->excludedModels)) {
            $this->excludedModels[] = $modelName;
        }
    }

    /**
     * Exclude some models from being restful accessible (e.g. BaseModel and so on).
     * @param array $modelNames
     */
    public function excludeModels(array $modelNames = [])
    {
        foreach ($modelNames as $modelName) {
            $this->excludeModel($modelName);
        }
    }

    /**
     * @return array
     */
    public function getExcludedModels()
    {
        return $this->excludedModels;
    }
} 