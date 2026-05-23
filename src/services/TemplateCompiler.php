<?php
/**
 * Template Compiler Service
 *
 * Compiles templates by replacing data-field and data-demo attributes
 * with actual user data from social accounts.
 */

class TemplateCompiler {
    /**
     * Compile template HTML with user profile and projects data
     *
     * @param string $templateHtml The template HTML with data-field and data-demo attributes
     * @param array|null $profile The user profile data from social account
     * @param array|null $projects The user projects/repos data
     * @return string Compiled HTML with demo content replaced by actual data
     */
    public static function compile(string $templateHtml, ?array $profile = null, ?array $projects = []): string {
        if (empty($templateHtml)) {
            return '';
        }

        $html = $templateHtml;

        // Simple field mappings from profile to data-field values
        $fieldMappings = self::getFieldMappings($profile);

        // Replace text content in elements with data-field attributes
        $html = self::replaceTextFields($html, $fieldMappings);

        // Handle avatar specially (update src attribute)
        $html = self::replaceAvatar($html, $fieldMappings);

        // Handle projects array
        if (!empty($projects) && is_array($projects)) {
            $html = self::replaceProjects($html, $projects);
        }

        // Handle skills
        if ($profile && isset($profile['skills'])) {
            $html = self::replaceSkills($html, $profile['skills']);
        }

        return $html;
    }

    /**
     * Get field mappings from profile data
     */
    private static function getFieldMappings(?array $profile): array {
        if (!$profile) {
            return [];
        }

        return [
            'name' => $profile['display_name'] ?? $profile['provider_username'] ?? '',
            'headline' => $profile['headline'] ?? '',
            'bio' => $profile['bio'] ?? '',
            'location' => $profile['location'] ?? '',
            'website' => $profile['website_url'] ?? '',
            'email' => $profile['metadata']['email'] ?? $profile['email'] ?? '',
            'phone' => $profile['metadata']['phone'] ?? '',
            'availability' => $profile['metadata']['availability'] ?? '',
            'experience' => $profile['metadata']['experience'] ?? '',
            'education' => $profile['metadata']['education'] ?? '',
            'avatar' => $profile['profile_image_url'] ?? $profile['provider_avatar_url'] ?? '',
            'followers' => $profile['follower_count'] ?? 0,
            'following' => $profile['following_count'] ?? 0,
            'github' => $profile['profile_url'] ?? '',
            'linkedin' => $profile['profile_url'] ?? '',
            'youtube' => $profile['profile_url'] ?? '',
            'twitter' => isset($profile['metadata']['twitter_username'])
                ? 'https://twitter.com/' . $profile['metadata']['twitter_username']
                : '',
            'username' => $profile['username'] ?? $profile['provider_username'] ?? '',
            'company' => $profile['metadata']['company'] ?? '',
        ];
    }

    /**
     * Replace text content in elements with data-field attributes
     */
    private static function replaceTextFields(string $html, array $fieldMappings): string {
        $textFields = [
            'name', 'headline', 'bio', 'location', 'website', 'email', 'phone',
            'availability', 'experience', 'education', 'username', 'company',
            'followers', 'following', 'github', 'linkedin', 'youtube', 'twitter'
        ];

        foreach ($textFields as $field) {
            if (empty($fieldMappings[$field])) {
                continue;
            }

            $value = $fieldMappings[$field];

            // Match elements with data-field="field" and replace content
            // Pattern: <tag ... data-field="field" ...>content</tag>
            $pattern = '/(<(\w+)[^>]*\sdata-field="' . preg_quote($field, '/') . '"[^>]*>)([^<]*)(<\/\\2>)/i';

            $html = preg_replace_callback($pattern, function($matches) use ($value) {
                return $matches[1] . $value . $matches[4];
            }, $html);
        }

        return $html;
    }

    /**
     * Replace avatar image src with actual profile image
     */
    private static function replaceAvatar(string $html, array $fieldMappings): string {
        if (empty($fieldMappings['avatar'])) {
            return $html;
        }

        $avatarUrl = $fieldMappings['avatar'];

        // Replace data-demo attribute with actual avatar URL
        $html = preg_replace('/data-demo="[^"]*"/', 'data-demo="' . htmlspecialchars($avatarUrl, ENT_QUOTES) . '"', $html);

        // Replace actual src if it's a placeholder (unsplash, etc)
        $html = preg_replace(
            '/src="https?:\/\/[^"]*(unsplash|placeholder|via\.placeholder)[^"]*"/i',
            'src="' . htmlspecialchars($avatarUrl, ENT_QUOTES) . '"',
            $html
        );

        return $html;
    }

    /**
     * Replace project cards with actual project data
     */
    private static function replaceProjects(string $html, array $projects): string {
        // Find the project template block
        // Looking for: <div data-field="projects" ...> ...project card... </div></div>
        if (preg_match('/(<div[^>]*data-field="projects"[^>]*>)([\s\S]*?)(<\/div>\s*<\/div>)/i', $html, $matches)) {
            $containerOpen = $matches[1];
            $projectTemplate = $matches[2];
            $containerClose = $matches[3];

            $renderedProjects = [];
            $maxProjects = min(count($projects), 6);

            for ($i = 0; $i < $maxProjects; $i++) {
                $project = $projects[$i];
                $projectHtml = $projectTemplate;

                // Replace data-demo-index with actual index
                $projectHtml = preg_replace('/data-demo-index="\d+"/', 'data-demo-index="' . $i . '"', $projectHtml);

                // Replace project field paths
                $projectFields = [
                    'title' => $project['title'] ?? $project['name'] ?? 'Project',
                    'description' => $project['description'] ?? '',
                    'url' => $project['url'] ?? $project['project_url'] ?? '#',
                    'language' => $project['language'] ?? $project['primaryLanguage'] ?? '',
                    'stars' => (string)($project['stars_count'] ?? $project['stars'] ?? 0),
                    'forks' => (string)($project['forks_count'] ?? 0),
                ];

                foreach ($projectFields as $field => $value) {
                    // Replace data-field="projects[0].field" patterns
                    $projectHtml = preg_replace(
                        '/data-field="projects\[\d+\]\.' . preg_quote($field, '/') . '"/',
                        'data-field="projects[' . $i . '].' . $field . '"',
                        $projectHtml
                    );

                    // Replace data-demo values
                    $projectHtml = preg_replace(
                        '/data-demo="[^"]*"/',
                        'data-demo="' . htmlspecialchars($value, ENT_QUOTES) . '"',
                        $projectHtml
                    );
                }

                $renderedProjects[] = $projectHtml;
            }

            // Reconstruct the projects section
            $html = str_replace(
                $containerOpen . $projectTemplate . $containerClose,
                $containerOpen . implode('', $renderedProjects) . $containerClose,
                $html
            );
        }

        return $html;
    }

    /**
     * Replace skill tags with actual skills
     */
    private static function replaceSkills(string $html, $skills): string {
        if (empty($skills)) {
            return $html;
        }

        if (!is_array($skills)) {
            $skills = [$skills];
        }

        $skillIndex = 0;
        foreach ($skills as $skill) {
            // Match skill span elements
            $pattern = '/(<span[^>]*data-field="skills"[^>]*>)[^<]*(<\/span>)/i';
            $html = preg_replace_callback($pattern, function($matches) use ($skill, &$skillIndex) {
                if ($skillIndex === 0) {
                    // Only replace the first skill tag with actual value
                    // Additional skill tags will keep their demo values
                    $skillIndex++;
                    return $matches[1] . htmlspecialchars($skill, ENT_QUOTES) . $matches[2];
                }
                return $matches[0];
            }, $html, 1); // Limit to 1 replacement per call
        }

        return $html;
    }

    /**
     * Extract data-field mappings from template HTML
     * Useful for admin to see what fields are used in a template
     */
    public static function extractFieldMappings(string $templateHtml): array {
        $fields = [];

        // Match all data-field attributes
        preg_match_all('/data-field="([^"]+)"/', $templateHtml, $matches);

        if ($matches) {
            foreach ($matches[1] as $field) {
                // Check if it's an array field like projects[0].title
                if (preg_match('/^(\w+)\[(\d+)\]\.(\w+)$/', $field, $arrayMatch)) {
                    $baseField = $arrayMatch[1];
                    $index = $arrayMatch[2];
                    $subField = $arrayMatch[3];

                    if (!isset($fields[$baseField])) {
                        $fields[$baseField] = ['type' => 'array', 'sub_fields' => []];
                    }
                    if (!in_array($subField, $fields[$baseField]['sub_fields'])) {
                        $fields[$baseField]['sub_fields'][] = $subField;
                    }
                } else {
                    if (!in_array($field, $fields)) {
                        $fields[$field] = ['type' => 'simple'];
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Validate template has required data-field attributes for a given profile
     */
    public static function validateTemplateForProfile(string $templateHtml, array $profile): array {
        $mappings = self::extractFieldMappings($templateHtml);
        $missing = [];

        // Check required fields from profile
        $requiredFields = ['name', 'bio', 'avatar'];

        foreach ($requiredFields as $required) {
            if (!isset($mappings[$required])) {
                $missing[] = $required;
            }
        }

        return [
            'valid' => empty($missing),
            'missing_fields' => $missing,
            'available_fields' => array_keys($mappings),
        ];
    }
}
