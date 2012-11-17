<?php
/**
 * Go! OOP&AOP PHP framework
 *
 * @copyright     Copyright 2012, Lissachenko Alexander <lisachenko.it@gmail.com>
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */

namespace Go\Core;

use ReflectionMethod;

use Go\Aop\Aspect;
use Go\Aop\Advisor;
use Go\Aop\Framework;
use Go\Aop\MethodMatcher;
use Go\Aop\Support;
use Go\Aop\Support\DefaultPointcutAdvisor;
use Go\Lang\Annotation;

/**
 * General aspect loader add common support for general advices, declared as annotations
 */
class GeneralAspectLoaderExtension implements AspectLoaderExtension
{
    /**
     * Mappings of string values to method modifiers
     *
     * @var array
     */
    protected static $methodModifiers = array(
        'public'    => ReflectionMethod::IS_PUBLIC,
        'protected' => ReflectionMethod::IS_PROTECTED,
        '::'        => ReflectionMethod::IS_STATIC,
        '*'         => 768 /* PUBLIC | PROTECTED */,
        '->'        => 0,
    );

    /**
     * General aspect loader works with annotations from aspect
     *
     * For extension that works with annotations additional metaInformation will be passed
     *
     * @return string
     */
    public function getKind()
    {
        return self::KIND_ANNOTATION;
    }

    /**
     * General aspect loader works only with methods of aspect
     *
     * @return string|array
     */
    public function getTarget()
    {
        return self::TARGET_METHOD;
    }

    /**
     * Checks if loader is able to handle specific point of aspect
     *
     * @param Aspect $aspect Instance of aspect
     * @param mixed|\ReflectionClass|\ReflectionMethod|\ReflectionProperty $reflection Reflection of point
     * @param mixed|null $metaInformation Additional meta-information, e.g. annotation for method
     *
     * @return boolean true if extension is able to create an advisor from reflection and metaInformation
     */
    public function supports(Aspect $aspect, $reflection, $metaInformation = null)
    {
        $isSupported  = false;
        $isSupported |= $metaInformation instanceof Annotation\After;
        $isSupported |= $metaInformation instanceof Annotation\Around;
        $isSupported |= $metaInformation instanceof Annotation\Before;
        return $isSupported;
    }

    /**
     * Loads definition from specific point of aspect into the container
     *
     * @param AspectContainer $container Instance of container
     * @param Aspect $aspect Instance of aspect
     * @param mixed|\ReflectionClass|\ReflectionMethod|\ReflectionProperty $reflection Reflection of point
     * @param mixed|null $metaInformation Additional meta-information, e.g. annotation for method
     */
    public function load(AspectContainer $container, Aspect $aspect, $reflection, $metaInformation = null)
    {
        $adviceCallback = $this->getAdvice($aspect, $reflection);

        // TODO: use general pointcut parser here instead of hardcoded regular expressions
        $pointcut = $this->parsePointcut($metaInformation);

        if ($pointcut instanceof MethodMatcher) {
            $advice = $this->getMethodInterceptor($metaInformation, $adviceCallback);
        }

        $container->registerAdvisor(new DefaultPointcutAdvisor($pointcut, $advice));
    }

    /**
     * @param $metaInformation
     * @param $adviceCallback
     * @return \Go\Aop\Intercept\MethodInterceptor
     * @throws \UnexpectedValueException
     */
    protected function getMethodInterceptor($metaInformation, $adviceCallback)
    {
        switch (true) {
            case ($metaInformation instanceof Annotation\Before):
                return new Framework\MethodBeforeInterceptor($adviceCallback);

            case ($metaInformation instanceof Annotation\After):
                return new Framework\MethodAfterInterceptor($adviceCallback);

            case ($metaInformation instanceof Annotation\Around):
                return new Framework\MethodAroundInterceptor($adviceCallback);

            case ($metaInformation instanceof Annotation\AfterThrowing):
                return new Framework\MethodAfterThrowingInterceptor($adviceCallback);

            default:
                throw new \UnexpectedValueException("Unsupported method meta class: " . get_class($metaInformation));
        }
    }


    /**
     * Returns an advice from aspect
     *
     * @param Aspect $aspect Aspect instance
     * @param ReflectionMethod $refMethod Reflection method of aspect
     *
     * @return callable Advice to call
     */
    private function getAdvice(Aspect $aspect, ReflectionMethod $refMethod)
    {
        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            return $refMethod->getClosure($aspect);
        } else {
            return function () use ($aspect, $refMethod) {
                return $refMethod->invokeArgs($aspect, func_get_args());
            };
        }
    }

    /**
     * Temporary method for parsing pointcuts
     *
     * @todo Replace this method with pointcut parser
     * @param Annotation\BaseAnnotation $metaInformation
     *
     * @throws \UnexpectedValueException If pointcut can not be parsed
     * @return \Go\Aop\Pointcut
     */
    private function parsePointcut($metaInformation)
    {
        // execution(public Example\Aspect\*->method*())
        // execution(protected Test\Class*::someStatic*Method())
        static $executionReg = '/
            ^execution\(
                (?P<modifier>public|protected|\*)\s+
                (?P<class>[\w\\\*]+)
                (?P<type>->|::)
                (?P<method>[\w\*]+)
                \(\*?\)
            \)$/x';

        if (preg_match($executionReg, $metaInformation->value, $matches)) {
            $classFilter = new Support\SimpleClassFilter($matches['class']);
            $modifier    = self::$methodModifiers[$matches['modifier']];
            $modifier   |= self::$methodModifiers[$matches['type']];
            $pointcut    = new Support\SignatureMethodPointcut(
                $matches['method'],
                $modifier
            );
            $pointcut->setClassFilter($classFilter);
            return $pointcut;
        }

        throw new \UnexpectedValueException("Unsupported pointcut: {$metaInformation->value}");
    }
}