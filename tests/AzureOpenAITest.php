<?php

use PHPUnit\Framework\TestCase;
use Docudoodle\Docudoodle;

class AzureOpenAITest extends TestCase
{
    public function testAzureOpenAIIntegrationConstructorParameters()
    {
        // Arrange
        $azureEndpoint = 'https://test-resource.openai.azure.com';
        $azureDeployment = 'test-deployment';
        $azureApiVersion = '2023-05-15';
        
        // Act
        $docudoodle = new Docudoodle(
            openaiApiKey: 'test-key',
            apiProvider: 'azure',
            azureEndpoint: $azureEndpoint,
            azureDeployment: $azureDeployment,
            azureApiVersion: $azureApiVersion
        );
        
        // Assert - if the constructor doesn't throw any exceptions, we can assume it works
        $this->assertIsObject($docudoodle);
    }

    public function testAzureConfigurationValuesAreUsedProperly()
    {
        // This is a reflection-based test to check that the properties are set correctly
        
        // Arrange
        $azureEndpoint = 'https://test-resource.openai.azure.com';
        $azureDeployment = 'test-deployment';
        $azureApiVersion = '2023-07-01'; // Custom version
        
        // Act
        $docudoodle = new Docudoodle(
            openaiApiKey: 'test-key',
            apiProvider: 'azure',
            azureEndpoint: $azureEndpoint,
            azureDeployment: $azureDeployment,
            azureApiVersion: $azureApiVersion
        );
        
        // Use reflection to access private properties
        $reflector = new ReflectionClass($docudoodle);
        
        $endpointProperty = $reflector->getProperty('azureEndpoint');
        $endpointProperty->setAccessible(true);
        
        $deploymentProperty = $reflector->getProperty('azureDeployment');
        $deploymentProperty->setAccessible(true);
        
        $versionProperty = $reflector->getProperty('azureApiVersion');
        $versionProperty->setAccessible(true);
        
        $providerProperty = $reflector->getProperty('apiProvider');
        $providerProperty->setAccessible(true);
        
        // Assert
        $this->assertEquals($azureEndpoint, $endpointProperty->getValue($docudoodle));
        $this->assertEquals($azureDeployment, $deploymentProperty->getValue($docudoodle));
        $this->assertEquals($azureApiVersion, $versionProperty->getValue($docudoodle));
        $this->assertEquals('azure', $providerProperty->getValue($docudoodle));
    }
} 