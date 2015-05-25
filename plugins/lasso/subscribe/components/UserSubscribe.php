<?php namespace Lasso\Subscribe\Components;

use Auth;
use Mail;
use Flash;
use Input;
use Redirect;
use Validator;
use ValidationException;
use ApplicationException;
use Cms\Classes\Page;
use Cms\Classes\ComponentBase;
use Lasso\Subscribe\Models\Subscribe as Subscriber;
use RainLab\User\Models\Settings as UserSettings;
use Exception;

class UserSubscribe extends ComponentBase
{

    public $subscribed;

    public function componentDetails()
    {
        return [
            'name'        => 'UserSubscribe Component',
            'description' => 'Provides extended registration and update forms for registered users to control subscription and zip',
            'icon'        => 'icon-user-plus'
        ];
    }

    public function defineProperties()
    {
        return [
            'redirect' => [
                'title'       => 'Redirect to',
                'description' => 'Page name to redirect to after update, sign in or registration.',
                'type'        => 'dropdown',
                'default'     => ''
            ],
            'paramCode' => [
                'title'       => 'rainlab.user::lang.account.code_param',
                'description' => 'rainlab.user::lang.account.code_param_desc',
                'type'        => 'string',
                'default'     => 'code'
            ]
        ];
    }

    public function getRedirectOptions()
    {
        return [''=>'- none -'] + Page::sortBy('baseFileName')->lists('baseFileName', 'baseFileName');
    }

    public function onRun()
    {
        $routeParameter = $this->property('paramCode');

        /*
         * Activation code supplied
         */
        if ($activationCode = $this->param($routeParameter)) {
            $this->onActivate(false, $activationCode);
        }

        $this->page['user'] = $this->user();
        $this->page['loginAttribute'] = $this->loginAttribute();
        $this->page['loginAttributeLabel'] = $this->loginAttributeLabel();
    }

    /**
     * Register the user
     */
    public function onRegister()
    {
        /*
         * Validate input
         */
        $data = post();

        if (!array_key_exists('password_confirmation', $data)) {
            $data['password_confirmation'] = post('password');
        }

        $rules = [
            'email'    => 'required|email|between:2,64',
            'password' => 'required|min:2',
            'zip' => 'required|numeric|between:5,9'
        ];

        if ($this->loginAttribute() == UserSettings::LOGIN_USERNAME) {
            $rules['username'] = 'required|between:2,64';
        }
        $userData = array_slice($data, 0, 4);

        $validation = Validator::make($data, $rules);
        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        /*
         * Register user
         */
        $requireActivation = UserSettings::get('require_activation', true);
        $automaticActivation = UserSettings::get('activate_mode') == UserSettings::ACTIVATE_AUTO;
        $userActivation = UserSettings::get('activate_mode') == UserSettings::ACTIVATE_USER;
        $user = Auth::register($userData, $automaticActivation);

        if($data['subscribe'] == 'Yes')
        {
            $user->extension->verificationDate = DateTime::getTimestamp();
        }

        $user->extension->affiliation = $data["affiliation"];
        /*
         * Activation is by the user, send the email
         */
        if ($userActivation) {
            $this->sendActivationEmail($user);

            Flash::success('An activation email has been sent to you at ' + $data["email"]);
        }

        /*
         * Automatically activated or not required, log the user in
         */
        if ($automaticActivation || !$requireActivation) {
            Auth::login($user);
        }

        /*
         * Redirect to the intended page after successful sign in
         */
        $redirectUrl = $this->pageUrl($this->property('redirect'));

        if ($redirectUrl = post('redirect', $redirectUrl)) {
            return Redirect::intended($redirectUrl);
        }
    }

    public function onUpdate()
    {
        if (!$user = $this->user())
            return;
        $data = post();
        $userData = array_slice($data, 0, 5);

        $user->save($userData);
        if($data['subscribe'] == "Yes")
        {
            $user->extension->verificationDate = DateTime::getTimestamp();
        }
        else
        {
            $user->extension->verificationDate = null;
        }
        $user->push();
        /*
         * Password has changed, reauthenticate the user
         */
        if (strlen(post('password'))) {
            Auth::login($user->reload(), true);
        }

        Flash::success(post('flash', Lang::get('Your account has been successfully updated.')));

        /*
         * Redirect to the intended page after successful update
         */
        $redirectUrl = $this->pageUrl($this->property('redirect'));

        if ($redirectUrl = post('redirect', $redirectUrl))
            return Redirect::to($redirectUrl);
    }
    public function onActivate($isAjax = true, $code = null)
    {
        try {
            $code = post('code', $code);

            /*
             * Break up the code parts
             */
            $parts = explode('!', $code);
            if (count($parts) != 2) {
                throw new ValidationException(['code' => Lang::get('Invalid activation code supplied')]);
            }

            list($userId, $code) = $parts;

            if (!strlen(trim($userId)) || !($user = Auth::findUserById($userId))) {
                throw new ApplicationException(Lang::get('No user found with given credentials.'));
            }

            if (!$user->attemptActivation($code)) {
                throw new ValidationException(['code' => Lang::get('Invalid activation code supplied')]);
            }

            if (($sub = Subscriber::email($user->email).get()) != null) {
                $user->extension->verificationDate = $sub->verificationDate;
                $this->removeOldSubscription($user);
                Flash::success(Lang::get('Your account has been successfully activated. Thanks for upgrading your subscription
                                            to a registered account!'));
            }
            else
            {
                Flash::success(Lang::get('Your account has been successfully activated. Thanks for Registering!'));
            }

            /*
             * Sign in the user
             */
            Auth::login($user);

        }
        catch (Exception $ex) {
            if ($isAjax) throw $ex;
            else Flash::error($ex->getMessage());
        }
    }

    protected function removeOldSubscription($user)
    {
        if (($sub = Subscriber::email($user->email).get()) != null)
            return false;
        $sub->delete();
    }

    public function onSendActivationEmail($isAjax = true)
    {
        try {
            if (!$user = $this->user()) {
                throw new ApplicationException(Lang::get('rainlab.user::lang.account.login_first'));
            }

            if ($user->is_activated) {
                throw new ApplicationException(Lang::get('Your account has already been activated'));
            }

            Flash::success(Lang::get('An activation email has been sent to you at ' + $user->email));

            $this->sendActivationEmail($user);

        }
        catch (Exception $ex) {
            if ($isAjax) throw $ex;
            else Flash::error($ex->getMessage());
        }

        /*
         * Redirect
         */
        $redirectUrl = $this->pageUrl($this->property('redirect'));

        if ($redirectUrl = post('redirect', $redirectUrl))
            return Redirect::to($redirectUrl);
    }

    protected function sendActivationEmail($user)
    {
        $code = implode('!', [$user->id, $user->getActivationCode()]);
        $link = $this->currentPageUrl([
            $this->property('paramCode') => $code
        ]);

        $data = [
            'name' => $user->name,
            'link' => $link,
            'code' => $code
        ];

        Mail::send('rainlab.user::mail.activate', $data, function($message) use ($user)
        {
            $message->to($user->email, $user->name);
        });
    }

}