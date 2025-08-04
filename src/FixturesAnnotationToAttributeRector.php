<?php

declare(strict_types=1);

namespace IW\PHPUnit\DbFixtures;

use Nette\Utils\Json;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\BetterPhpDocParser\PhpDocManipulator\PhpDocTagRemover;
use Rector\Comments\NodeDocBlock\DocBlockUpdater;
use Rector\PhpAttribute\NodeFactory\PhpAttributeGroupFactory;
use Rector\PHPUnit\NodeAnalyzer\TestsNodeAnalyzer;
use Rector\Rector\AbstractRector;
use Rector\ValueObject\PhpVersionFeature;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Rector\BetterPhpDocParser\PhpDoc\DoctrineAnnotationTagValueNode;

final class FixturesAnnotationToAttributeRector extends AbstractRector implements MinPhpVersionInterface
{
    public function __construct(
        private readonly TestsNodeAnalyzer $testsNodeAnalyzer,
        private readonly PhpAttributeGroupFactory $phpAttributeGroupFactory,
        private readonly PhpDocTagRemover $phpDocTagRemover,
        private readonly DocBlockUpdater $docBlockUpdater,
        private readonly PhpDocInfoFactory $phpDocInfoFactory
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Change @fixtures annotation to #[Fixtures] attribute',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    use PHPUnit\Framework\TestCase;

                    final class SomeFixture extends TestCase
                    {
                        /**
                         * @fixtures mysql write users.yml jobs.yml
                         */
                        public function test(): void
                        {
                        }
                    }
                    CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
                    use IW\PHPUnit\DbFixtures\Fixtures;
                    use PHPUnit\Framework\TestCase;

                    final class SomeFixture extends TestCase
                    {
                        #[Fixtures('mysql', 'write', 'users.yml', 'jobs.yml')]
                        public function test(): void
                        {
                        }
                    }
                    CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    /**
     * @param ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->testsNodeAnalyzer->isTestClassMethod($node)) {
            return null;
        }

        $phpDocInfo = $this->phpDocInfoFactory->createFromNode($node);
        if (! $phpDocInfo instanceof PhpDocInfo) {
            return null;
        }

        foreach ($phpDocInfo->getTagsByName('fixtures') as $fixturesPhpDocTagNode) {
            /** @var PhpDocTagNode $fixturesPhpDocTagNode */

            // extract attribute params configuration
            if ($fixturesPhpDocTagNode->value instanceof DoctrineAnnotationTagValueNode) {
                $fixtures = explode(' ', $fixturesPhpDocTagNode->value->getAttribute('attribute_comment'));
            } elseif ($fixturesPhpDocTagNode->value instanceof \PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode) {
                $fixtures = explode(' ', $fixturesPhpDocTagNode->value->value);
            } else {
                continue;
            }

            // test from doc blocks
            $this->phpDocTagRemover->removeTagValueFromNode($phpDocInfo, $fixturesPhpDocTagNode);


            $attributeGroups[] = $this->phpAttributeGroupFactory
                ->createFromClassWithItems(Fixtures::class, $fixtures);
        }

        $this->docBlockUpdater->updateRefactoredNodeWithPhpDocInfo($node);
        $node->attrGroups = array_merge($node->attrGroups, $attributeGroups ?? []);

        return $node;
    }

    public function provideMinPhpVersion(): int
    {
        return PhpVersionFeature::ATTRIBUTES;
    }
}
