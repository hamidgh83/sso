<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Desarrolla2\Cache\File as FileCache;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\Broker;
use App\Service\Server;
use Loggy;
use App\Service\ExceptionInterface as SSOException;

class BrokerController extends AbstractController
{
    public $broker;
    private $session;

    function __construct(SessionInterface $session)
    {
        $this->broker = new Broker(
            'http://localhost:8080/attach',
            'Alice',
            '8iwzik1bwd'
        );

        $this->session = $session;
    }

    /**
     * @Route("/broker", name="broker")
     */
    public function index()
    {
        // Handle error from SSO server
        if (isset($_GET['sso_error'])) {
            require __DIR__ . '/../error.php';
            exit();
        }

        // Handle verification from SSO server
        if (isset($_GET['sso_verify'])) {
            $this->broker->verify($_GET['sso_verify']);
            $url = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $redirectUrl = preg_replace('/sso_verify=\w+&|[?&]sso_verify=\w+$/', '', $url);

            return $this->redirect($redirectUrl);
        }

        // Attach through redirect if the client isn't attached yet.
        if (!$this->broker->isAttached()) {
            $returnUrl = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $attachUrl = $this->broker->getAttachUrl(['return_url' => $returnUrl]);

            return $this->redirect($attachUrl);
        }

        // Get the user info from the SSO server via the API.
        try {
            $userInfo = $this->broker->request('GET', '/api/info');
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException($exception->getMessage(), 500, $exception);
        }

        return $this->render('broker/index.html.twig', [
            'controller_name' => 'BrokerController',
            'userInfo'        => $userInfo
        ]);
    }

    /**
     * @Route("/landing", name="landing")
     */
    public function landing() {
        // Get the user info from the SSO server via the API.
        try {
            $userInfo = $this->broker->request('GET', '/api/info');
        } catch (\RuntimeException $exception) {
            var_dump($exception->getMessage());
            exit();
        }
        
        return $this->render('broker/landing.html.twig', [
            'broker' => $this->broker->getBrokerId(),
            'userInfo' => json_encode($userInfo, JSON_PRETTY_PRINT)
        ]);
    }

    /**
     * @Route("/logout", name="logout")
     */
    public function logout() {
        try {
            $this->broker->request('POST', 'api/logout');
        } catch (\RuntimeException $exception) {
            var_dump($exception->getMessage());
            exit();
        }
        
        return $this->redirectToRoute('login');
    }

    /**
     * @Route("/login", name="login")
     */
    public function login() {
        // Handle error from SSO server
        if (isset($_GET['sso_error'])) {
            require __DIR__ . '/../error.php';
            exit();
        }

        // Handle verification from SSO server
        if (isset($_GET['sso_verify'])) {
            $this->broker->verify($_GET['sso_verify']);
            $url = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $redirectUrl = preg_replace('/sso_verify=\w+&|[?&]sso_verify=\w+$/', '', $url);

            return $this->redirect($redirectUrl);
        }

        // Attach through redirect if the client isn't attached yet.
        if (!$this->broker->isAttached()) {
            $returnUrl = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $attachUrl = $this->broker->getAttachUrl(['return_url' => $returnUrl]);
            
            return $this->redirect($attachUrl);
        }
        // Handle POST request
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $credentials = [
                    'username' => $_POST['username'],
                    'password' => $_POST['password']
                ];
                
                $this->broker->request('POST', '/api/login', $credentials);
                
                return $this->redirect('/landing');
            } catch (\RuntimeException $exception) {
                throw new \RuntimeException($exception->getMessage(), 500, $exception);
            }
        }

        return $this->render('broker/login.html.twig', [
            'broker' => $this->broker->getBrokerId()
        ]);
    }


    /**
     * @Route("/api/logout", name="logoutapi")
     */
    public function apiLogout() {
        $this->startBrockerSession();

        // Clear the session user.
        unset($_SESSION['user']);

        // Done (no output)
        return new JsonResponse([]);
    }

    /**
     * @Route("/api/login", name="loginapi")
     */
    public function apiLogin() {
        $this->startBrockerSession();
        // Take the username and password from the POST params.
        $username = $_POST['username'];
        $password = $_POST['password'];

        // Authenticate the user.
        // if (!isset($config['users'][$username]) || !password_verify($password, $config['users'][$username]['password'])) {
        //     http_response_code(400);
        //     header('Content-Type: application/json');
        //     echo json_encode(['error' => "Invalid credentials"]);
        //     exit();
        // }

        // Store the current user in the session.
        $this->session->set('user', $username);

        // Output user info as JSON.
        $info = ['username' => $username] + $this->getParameter('users')[$username];
        unset($info['password']);

        return new JsonResponse($info);
    }

    /**
     * @Route("/api/info", name="infoapi")
     */
    public function info() {
        $this->startBrockerSession();
        // Get the username from the session
        $username = $this->session->get('user');
        // $username = 'jackie';
        // Output user info as JSON.
        $info = ['username' => $username] + $this->getParameter('users')[$username];
        unset($info['password']);

        return new JsonResponse($info);
    }

    private function startBrockerSession() {
        // Instantiate the SSO server.
        $config = $this->getParameter('brokers');
        $ssoServer = (new Server(
            function (string $id) use ($config) {
                return $config[$id] ?? null;  // Callback to get the broker secret. You might fetch this from DB.
            },
            new FileCache(sys_get_temp_dir())            // Any PSR-16 compatible cache
        ))->withLogger(new Loggy('SSO'));

        // Start the session using the broker bearer token (rather than a session cookie).
        try {
            $ssoServer->startBrokerSession();
        } catch (SsoException $exception) {
            $code = $exception->getCode();
            $message = $code === 403
                ? "Invalid or expired bearer token"
                : $exception->getMessage();

            http_response_code($code);
            if ($code === 401) {
                header('WWW-Authenticate: Bearer');
            }

            header('Content-Type: application/json');
            echo json_encode(['error' => $message]);

            exit();
        }

        return $ssoServer;
    }
}
