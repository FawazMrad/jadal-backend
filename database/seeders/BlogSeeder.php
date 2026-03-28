<?php

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        try {
            // ── Categories ────────────────────────────────────────────────────
            $categoriesData = [
                ['name' => 'المناظرة والجدال',   'slug' => 'debate-and-argumentation'],
                ['name' => 'مهارات التواصل',     'slug' => 'communication-skills'],
                ['name' => 'أخبار النادي',        'slug' => 'club-news'],
            ];

            $categories = collect();
            foreach ($categoriesData as $cat) {
                $categories->push(BlogCategory::firstOrCreate(['slug' => $cat['slug']], array_merge($cat, ['created_at' => now()])));
            }

            $this->command->info('✓ Blog categories seeded: ' . $categories->count());

            // ── Tags ──────────────────────────────────────────────────────────
            $tagsData = [
                'مناظرة', 'خطابة', 'تفكير نقدي', 'مهارات', 'شباب',
                'Debate', 'Speech', 'Logic', 'Argumentation', 'Students',
            ];

            $tags = collect();
            foreach ($tagsData as $tagName) {
                $slug = Str::slug($tagName) ?: 'tag-' . Str::random(6);
                $tags->push(BlogTag::firstOrCreate(['slug' => $slug], ['name' => $tagName, 'slug' => $slug, 'created_at' => now()]));
            }

            $this->command->info('✓ Blog tags seeded: ' . $tags->count());

            // ── Posts ─────────────────────────────────────────────────────────
            $authors = User::whereIn('role', ['admin', 'trainer', 'debater'])->get();

            $postsData = [
                [
                    'title'  => 'دليلك الشامل لبناء حجج قوية في المناظرات',
                    'status' => 'published',
                    'content' => "بناء الحجة المنطقية هو أساس أي مناظرة ناجحة. في هذا المقال، سنستعرض الخطوات الأساسية لبناء حجج مقنعة.\n\nأولاً: تحديد الادعاء الرئيسي\nيجب أن يكون ادعاؤك واضحاً ومحدداً وقابلاً للدفاع عنه بالأدلة.\n\nثانياً: جمع الأدلة الداعمة\nالأدلة هي عماد أي حجة قوية. استخدم إحصائيات، دراسات علمية، وأمثلة واقعية.\n\nثالثاً: توقع الاعتراضات\nفكر مسبقاً في الحجج المضادة وكيف ستتعامل معها بشكل فعّال.",
                ],
                [
                    'title'  => 'The Art of Persuasive Public Speaking',
                    'status' => 'published',
                    'content' => "Public speaking is both an art and a skill that can be mastered with practice. Here are the key elements of persuasive speaking.\n\nVoice Modulation\nVary your pitch, pace, and volume to maintain audience engagement and emphasize key points.\n\nBody Language\nOver 55% of communication is non-verbal. Stand tall, use purposeful gestures, and maintain eye contact.\n\nStructure Your Speech\nEvery great speech has a clear beginning, middle, and end. Guide your audience through your argument logically.",
                ],
                [
                    'title'  => 'نتائج بطولة نادي جدل الربيعية ٢٠٢٥',
                    'status' => 'published',
                    'content' => "بكل فخر واعتزاز، يُعلن نادي جدل عن نتائج البطولة الربيعية الأولى لعام ٢٠٢٥.\n\nالفائز: فريق الفجر\nبعد مسيرة مميزة خلال البطولة، تمكن فريق الفجر من الفوز باللقب بعد مناظرة نهائية رائعة.\n\nالمركز الثاني: فريق النسر\nقدّم الفريق أداءً احترافياً عالياً طوال البطولة.\n\nشكراً لجميع المشاركين والمحكمين على جهودهم المميزة.",
                ],
                [
                    'title'  => 'Critical Thinking in Competitive Debate',
                    'status' => 'published',
                    'content' => "Critical thinking is the cornerstone of competitive debate. It encompasses the ability to analyze, evaluate, and synthesize information to form well-reasoned arguments.\n\nKey Components:\n1. Analysis: Break down complex issues into manageable components\n2. Evaluation: Assess the validity and reliability of evidence\n3. Synthesis: Combine different pieces of evidence to form coherent arguments\n4. Inference: Draw logical conclusions from available evidence",
                ],
                [
                    'title'  => 'كيف تستعد لمناظرتك الأولى؟',
                    'status' => 'published',
                    'content' => "إذا كانت هذه مناظرتك الأولى، فلا داعي للقلق! إليك خطوات عملية للتحضير بشكل مثالي.\n\n١. افهم القضية جيداً\nاقرأ عن الموضوع من مصادر متعددة ومتنوعة.\n\n٢. جهّز حججك مسبقاً\nاكتب قائمة بأقوى حججك وأدلة كل منها.\n\n٣. تدرّب على الإلقاء\nسجّل نفسك وراجع أسلوبك في التقديم.",
                ],
                [
                    'title'  => 'Understanding Debate Formats: A Comprehensive Guide',
                    'status' => 'published',
                    'content' => "Different debate formats serve different purposes and test different skills. Understanding these formats helps debaters prepare effectively.\n\nBritish Parliamentary (BP)\nThe most popular format globally, featuring four teams of two speakers each. Known for its complexity and emphasis on strategic thinking.\n\nAsian Parliamentary (AP)\nA two-team format with three speakers per side. Popular in Asian debate circuits and WUDC-style competitions.\n\nWorld Schools\nDesigned for pre-university students, this format combines prepared and impromptu debates.",
                ],
                [
                    'title'  => 'Research Techniques for Debate Preparation',
                    'status' => 'draft',
                    'content' => 'Draft content about research techniques...',
                ],
                [
                    'title'  => 'مهارات الاستماع الفعّال في المناظرات',
                    'status' => 'pending_review',
                    'content' => 'محتوى قيد المراجعة عن مهارات الاستماع...',
                ],
                [
                    'title'  => 'البرهان والتفنيد: أساس المناظرة الاحترافية',
                    'status' => 'published',
                    'content' => "البرهان والتفنيد هما الأداتان الأساسيتان في أي مناظرة احترافية.\n\nالبرهان\nهو تقديم الدليل القاطع الذي يدعم ادعاءك بشكل لا يقبل الجدل.\n\nالتفنيد\nهو الرد على حجج الخصم بطريقة منطقية وفعّالة دون الهجوم الشخصي.",
                ],
                [
                    'title'  => 'Emotional Intelligence in Debate',
                    'status' => 'published',
                    'content' => "Emotional intelligence (EQ) plays a crucial role in competitive debate. High EQ debaters can manage their emotions under pressure, empathize with opponents' viewpoints, and connect more effectively with their audience.\n\nSelf-Awareness\nUnderstanding your emotional triggers helps you maintain composure during intense exchanges.\n\nSelf-Regulation\nThe ability to stay calm and focused even when your arguments are challenged.",
                ],
                [
                    'title'  => 'إعلان عن برنامج تدريبي مكثف لتطوير مهارات المناظرة',
                    'status' => 'published',
                    'content' => "يسعد نادي جدل الإعلان عن إطلاق برنامج تدريبي مكثف موجه للمبتدئين والمتوسطين.\n\nمحاور البرنامج:\n- بناء الحجة المنطقية\n- مهارات الإلقاء والتواصل\n- التفنيد والرد الفوري\n- الاستراتيجيات المتقدمة في المناظرة\n\nللتسجيل تواصل مع مسؤول النادي.",
                ],
                [
                    'title'  => 'The Role of Evidence in Academic Debate',
                    'status' => 'rejected',
                    'content' => 'Content about evidence that was rejected...',
                ],
                [
                    'title'  => 'استراتيجيات الرد الفوري في المناظرات',
                    'status' => 'pending_review',
                    'content' => 'محتوى قيد المراجعة عن الرد الفوري...',
                ],
                [
                    'title'  => 'Debate Ethics: Winning with Integrity',
                    'status' => 'published',
                    'content' => "Winning at any cost should never be a debater's motto. True debate excellence combines skill with integrity.\n\nFair Play\nRepresent your opponent's arguments accurately and honestly, even when you disagree.\n\nRespect\nMaintain respectful discourse even in heated exchanges. Personal attacks weaken your credibility.",
                ],
                [
                    'title'  => 'تطوير ثقتك بنفسك على المنصة',
                    'status' => 'draft',
                    'content' => 'مسودة مقال عن تطوير الثقة بالنفس...',
                ],
            ];

            foreach ($postsData as $postData) {
                $slug = Str::slug($postData['title']) . '-' . rand(100, 9999);

                $post = BlogPost::create([
                    'author_id'       => $authors->random()->id,
                    'title'           => $postData['title'],
                    'slug'            => $slug,
                    'content'         => $postData['content'],
                    'status'          => $postData['status'],
                    'cover_image_url' => rand(0, 1) ? 'https://picsum.photos/seed/' . rand(1, 100) . '/800/400' : null,
                    'reviewer_comment' => $postData['status'] === 'rejected' ? 'المحتوى لا يستوفي معايير النشر.' : null,
                    'published_at'    => $postData['status'] === 'published' ? now()->subDays(rand(1, 90)) : null,
                ]);

                // Attach 1–2 categories
                $post->categories()->attach($categories->random(min(rand(1, 2), $categories->count()))->pluck('id')->toArray());

                // Attach 2–4 tags
                $post->tags()->attach($tags->random(min(rand(2, 4), $tags->count()))->pluck('id')->toArray());
            }

            $this->command->info('✓ Blog posts seeded: ' . count($postsData) . ' posts with categories and tags.');
        } catch (\Throwable $e) {
            $this->command->error('BlogSeeder failed: ' . $e->getMessage());
        }
    }
}
