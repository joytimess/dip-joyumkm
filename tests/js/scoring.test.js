import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { calculateScores, roundScore, categoryForScore } from '../../resources/js/scoring.js';

const root = fileURLToPath(new URL('../../', import.meta.url));
const fixtures = JSON.parse(readFileSync(new URL('../Fixtures/scoring.json', import.meta.url), 'utf8'));
const scoring = JSON.parse(execFileSync('php', ['-r', "require 'vendor/autoload.php'; echo json_encode((require 'config/umkm.php')['scoring']);"], { cwd: root, encoding: 'utf8' }));
let nextId = 1;
const questionnaire = {
    scoring,
    factors: fixtures.factors.map((factor) => ({ ...factor, questions: Array.from({ length: factor.question_count }, () => ({ id: nextId++ })) })),
};

for (const fixture of fixtures.cases) {
    test(`shared scoring: ${fixture.name}`, () => {
        const answers = questionnaire.factors.flatMap((factor) => fixture.values[factor.code].map((value, index) => ({ question_id: factor.questions[index].id, value })));
        const actual = calculateScores(questionnaire, answers);
        assert.deepEqual(actual.factor_scores, fixture.expected.factor_scores);
        assert.equal(actual.average_score, fixture.expected.average_score);
        assert.equal(actual.category, fixture.expected.category);
        assert.equal(actual.answered_count, answers.length);
        assert.equal(actual.total_questions, questionnaire.factors.reduce((total, factor) => total + factor.question_count, 0));
    });
}

for (const fixture of fixtures.rounding) {
    test(`shared half-up rounding: ${fixture.value}`, () => {
        const score = roundScore(fixture.value, scoring.score_precision);
        assert.equal(score, fixture.expected_score);
        assert.equal(categoryForScore(score, scoring), fixture.expected_category);
        assert.equal(categoryForScore(fixture.value, scoring), fixture.expected_category);
    });
}
